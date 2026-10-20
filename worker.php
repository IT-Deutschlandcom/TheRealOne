<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/Mailer.php';

$lockName = 'the_real_one_campaign_worker';
if ((int) $pdo->query("SELECT GET_LOCK('{$lockName}', 0)")->fetchColumn() !== 1) {
    exit(0);
}

try {
    $pdo->exec(
        "UPDATE campaign_queue SET status='unknown', completed_at=UTC_TIMESTAMP(),
         last_error='Worker wurde während der SMTP-Übertragung unterbrochen; Annahmestatus unbekannt.'
         WHERE status='processing' AND processing_started_at < UTC_TIMESTAMP() - INTERVAL 5 MINUTE"
    );
    $pdo->exec(
        "UPDATE campaigns c JOIN campaign_queue q ON q.campaign_id=c.id
         SET c.status='paused', c.next_send_at=NULL
         WHERE c.status='sending' AND q.status='unknown'"
    );

    $pdo->beginTransaction();
    $queue = $pdo->query(
        "SELECT q.id AS queue_id, q.campaign_id, q.attempts, c.list_id, c.name, c.from_name, c.from_email,
         c.reply_to, c.subject, c.html_content, c.text_content, c.send_interval_seconds
         FROM campaign_queue q JOIN campaigns c ON c.id=q.campaign_id
         WHERE q.status='queued' AND q.available_at<=UTC_TIMESTAMP()
           AND c.status='sending' AND c.next_send_at<=UTC_TIMESTAMP()
         ORDER BY c.next_send_at, q.id LIMIT 1 FOR UPDATE"
    )->fetch();
    if (!$queue) {
        $pdo->commit();
        exit(0);
    }
    $claim = $pdo->prepare(
        "UPDATE campaign_queue SET status='processing', attempts=attempts+1,
         processing_started_at=UTC_TIMESTAMP() WHERE id=:id AND status='queued'"
    );
    $claim->execute(['id' => $queue['queue_id']]);
    $pdo->commit();

    $contactQuery = $pdo->prepare(
        "SELECT ec.id, ec.email, ec.first_name, ec.last_name
         FROM campaign_queue q
         JOIN email_contacts ec ON ec.id=q.contact_id AND ec.status='active'
         WHERE q.id=:queue_id
           AND EXISTS (
               SELECT 1 FROM email_list_contacts lc
               WHERE lc.list_id=:list_id AND lc.contact_id=ec.id
           )
           AND EXISTS (
               SELECT 1 FROM contact_consents cc
               WHERE cc.contact_id=ec.id AND cc.granted_at<=UTC_TIMESTAMP() AND cc.evidence_source<>''
           )
           AND NOT EXISTS (
               SELECT 1 FROM suppression_list sl WHERE sl.normalized_email=ec.normalized_email
           )"
    );
    $contactQuery->execute(['queue_id' => $queue['queue_id'], 'list_id' => $queue['list_id']]);
    $contact = $contactQuery->fetch();
    if (!$contact) {
        $pdo->prepare(
            "UPDATE campaign_queue SET status='cancelled', completed_at=UTC_TIMESTAMP(),
             last_error='Kontakt ist nicht mehr aktiv oder besitzt keine gültige dokumentierte Freigabe.'
             WHERE id=:id AND status='processing'"
        )->execute(['id' => $queue['queue_id']]);
        advance_campaign($pdo, (int) $queue['campaign_id']);
        exit(0);
    }

    try {
        $profile = \MailMarketing\active_smtp_profile($pdo, $config);
    } catch (Throwable $exception) {
        error_log('Active SMTP profile could not be loaded; campaign paused.');
        $profile = null;
    }
    if (!$profile) {
        $pdo->prepare(
            "UPDATE campaign_queue SET status='queued', available_at=DATE_ADD(UTC_TIMESTAMP(), INTERVAL 5 MINUTE),
             processing_started_at=NULL, attempts=GREATEST(attempts-1,0),
             last_error='SMTP-Profil nicht konfiguriert.' WHERE id=:id AND status='processing'"
        )->execute(['id' => $queue['queue_id']]);
        $pdo->prepare("UPDATE campaigns SET status='paused' WHERE id=:id AND status='sending'")
            ->execute(['id' => $queue['campaign_id']]);
        fwrite(STDERR, "No active SMTP profile; campaign paused.\n");
        exit(1);
    }

    $currentStatus = $pdo->prepare(
        "SELECT c.status, q.status AS queue_status
         FROM campaigns c JOIN campaign_queue q ON q.campaign_id=c.id WHERE q.id=:id"
    );
    $currentStatus->execute(['id' => $queue['queue_id']]);
    $state = $currentStatus->fetch();
    if (!$state || $state['status'] !== 'sending' || $state['queue_status'] !== 'processing') {
        $pdo->prepare(
            "UPDATE campaign_queue SET status='cancelled', completed_at=UTC_TIMESTAMP(),
             last_error='Kampagne wurde vor dem Versand pausiert oder abgebrochen.'
             WHERE id=:id AND status='processing'"
        )->execute(['id' => $queue['queue_id']]);
        exit(0);
    }

    try {
        $url = \MailMarketing\unsubscribe_url((int) $contact['id'], $config);
        $result = \MailMarketing\send_campaign_message($profile, $queue, $contact, $url);
    } catch (Throwable $exception) {
        error_log('Campaign send preparation failed: ' . $exception->getMessage());
        $result = ['accepted' => false, 'smtp_code' => null, 'error' => 'Versandvorbereitung fehlgeschlagen; manuelle Prüfung erforderlich.'];
    }

    $temporaryRetry = !$result['accepted'] && ($result['temporary'] ?? false) &&
        (int) $queue['attempts'] + 1 < (int) ($config['mailer']['max_attempts'] ?? 3);
    $status = $result['accepted'] ? 'accepted' : ($result['smtp_code'] !== null ? 'failed' : 'unknown');
    $finish = $pdo->prepare(
        'UPDATE campaign_queue SET status=:status, available_at=IF(:retry_available=1,
         DATE_ADD(UTC_TIMESTAMP(), INTERVAL GREATEST(60, attempts * 300) SECOND), available_at),
         processing_started_at=NULL, completed_at=IF(:retry_complete=1,NULL,UTC_TIMESTAMP()), smtp_code=:smtp_code,
         last_error=:error WHERE id=:id AND status="processing"'
    );
    $finish->execute([
        'status' => $temporaryRetry ? 'queued' : $status,
        'retry_available' => $temporaryRetry ? 1 : 0,
        'retry_complete' => $temporaryRetry ? 1 : 0,
        'smtp_code' => $result['smtp_code'],
        'error' => $result['error'], 'id' => $queue['queue_id'],
    ]);
    if ($status === 'unknown' || in_array($result['smtp_code'], [421, 530, 535], true)) {
        $pdo->prepare("UPDATE campaigns SET status='paused', next_send_at=NULL WHERE id=:id AND status='sending'")
            ->execute(['id' => $queue['campaign_id']]);
        error_log('Campaign paused after uncertain SMTP status or critical SMTP response.');
    } else {
        advance_campaign($pdo, (int) $queue['campaign_id']);
    }
    fwrite(STDOUT, "Campaign queue item {$queue['queue_id']}: {$status}\n");
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Campaign worker failed: ' . $exception->getMessage());
    fwrite(STDERR, "Campaign worker failed. Check the server log.\n");
    exit(1);
} finally {
    $pdo->query("SELECT RELEASE_LOCK('{$lockName}')");
}

function advance_campaign(PDO $pdo, int $campaignId): void
{
    $remaining = $pdo->prepare(
        "SELECT COUNT(*) FROM campaign_queue WHERE campaign_id=:id AND status IN ('queued','processing')"
    );
    $remaining->execute(['id' => $campaignId]);
    if ((int) $remaining->fetchColumn() === 0) {
        $pdo->prepare(
            "UPDATE campaigns SET status='completed', next_send_at=NULL WHERE id=:id AND status='sending'"
        )->execute(['id' => $campaignId]);
        return;
    }
    $campaign = $pdo->prepare('SELECT send_interval_seconds FROM campaigns WHERE id=:id AND status="sending"');
    $campaign->execute(['id' => $campaignId]);
    $interval = (int) $campaign->fetchColumn();
    if ($interval < 60 || $interval > 86400) {
        return;
    }
    $pdo->prepare(
        "UPDATE campaigns SET next_send_at=DATE_ADD(UTC_TIMESTAMP(), INTERVAL {$interval} SECOND)
         WHERE id=:id AND status='sending'"
    )->execute(['id' => $campaignId]);
}
