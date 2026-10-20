<?php
declare(strict_types=1);

namespace MailMarketing;

use PDO;
use RuntimeException;

function process_bounces(PDO $pdo, array $config, int $limit = 50): array
{
    if (!function_exists('imap_open')) {
        return ['processed' => 0, 'error' => 'PHP IMAP-Erweiterung nicht installiert.'];
    }
    $mailbox = $pdo->query('SELECT * FROM bounce_mailboxes WHERE id=1 AND is_active=1')->fetch();
    if (!$mailbox) {
        return ['processed' => 0, 'error' => null];
    }
    $password = decrypt_secret($mailbox['password_encrypted'], $config);
    $flags = $mailbox['encryption'] === 'ssl' ? '/imap/ssl' : '/imap/tls';
    $folder = '{' . $mailbox['host'] . ':' . (int) $mailbox['port'] . $flags . '}' . $mailbox['folder_name'];
    $imap = @imap_open($folder, $mailbox['username'], $password, OP_SILENT, 1);
    if ($imap === false) {
        throw new RuntimeException('Bounce-Postfachverbindung fehlgeschlagen.');
    }
    $processed = 0;
    try {
        $uids = imap_search($imap, 'UID ' . ((int) $mailbox['last_uid'] + 1) . ':*', SE_UID);
        if (!is_array($uids)) {
            $uids = [];
        }
        sort($uids, SORT_NUMERIC);
        $uids = array_slice($uids, 0, max(1, min($limit, 100)));
        $lastUid = (int) $mailbox['last_uid'];
        foreach ($uids as $uid) {
            $lastUid = max($lastUid, (int) $uid);
            $overview = imap_fetch_overview($imap, (string) $uid, FT_UID);
            $subject = is_array($overview) && isset($overview[0]->subject) ? (string) $overview[0]->subject : '';
            $body = imap_body($imap, (int) $uid, FT_UID);
            if (is_string($body)) {
                $body = substr($body, 0, 1_000_000);
            }
            if (!is_string($body) || !preg_match('/(?:Final-Recipient|Original-Recipient)\s*:\s*(?:rfc822\s*;\s*)?([^\s;<>]+@[^\s;<>]+)/i', $body, $recipientMatch)) {
                imap_setflag_full($imap, (string) $uid, '\\Seen', ST_UID);
                continue;
            }
            $email = strtolower(trim($recipientMatch[1], " \t\r\n<>"));
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                imap_setflag_full($imap, (string) $uid, '\\Seen', ST_UID);
                continue;
            }
            preg_match('/\bStatus\s*:\s*([245]\.\d+\.\d+)/i', $body, $statusMatch);
            preg_match('/\bAction\s*:\s*(failed|delayed|delivered|relayed|expanded)/i', $body, $actionMatch);
            $status = $statusMatch[1] ?? '';
            $action = strtolower($actionMatch[1] ?? '');
            $isBounce = $action === 'failed' || $action === 'delayed' || str_starts_with($status, '5.') || str_starts_with($status, '4.');
            if ($isBounce) {
                $type = str_starts_with($status, '5.') ? 'hard' : 'soft';
                $pdo->beginTransaction();
                $contactQuery = $pdo->prepare('SELECT id, normalized_email FROM email_contacts WHERE normalized_email=:email FOR UPDATE');
                $contactQuery->execute(['email' => $email]);
                $contact = $contactQuery->fetch();
                if ($contact) {
                    $recent = $pdo->prepare(
                        'SELECT COUNT(*) FROM bounce_events WHERE contact_id=:contact AND bounce_type="soft"
                         AND received_at>=UTC_TIMESTAMP()-INTERVAL 30 DAY'
                    );
                    $recent->execute(['contact' => $contact['id']]);
                    $priorSoft = (int) $recent->fetchColumn();
                    $effectiveType = $type === 'soft' && $priorSoft >= 2 ? 'hard' : $type;
                    $queueQuery = $pdo->prepare(
                        "SELECT id, campaign_id FROM campaign_queue
                         WHERE contact_id=:contact AND status='accepted' ORDER BY completed_at DESC LIMIT 1"
                    );
                    $queueQuery->execute(['contact' => $contact['id']]);
                    $queue = $queueQuery->fetch() ?: null;
                    $diagnostic = preg_replace('/[\x00-\x1F\x7F]+/', ' ', $subject . ' ' . ($statusMatch[1] ?? ''));
                    $insert = $pdo->prepare(
                        'INSERT IGNORE INTO bounce_events
                         (mailbox_id, source_uid, contact_id, campaign_id, queue_id, bounce_type, smtp_status, diagnostic)
                         VALUES (1, :uid, :contact, :campaign, :queue, :type, :status, :diagnostic)'
                    );
                    $insert->execute([
                        'uid' => (int) $uid, 'contact' => $contact['id'], 'campaign' => $queue['campaign_id'] ?? null,
                        'queue' => $queue['id'] ?? null, 'type' => $effectiveType,
                        'status' => $status ?: null, 'diagnostic' => substr((string) $diagnostic, 0, 500),
                    ]);
                    if ($insert->rowCount() === 1 && $effectiveType === 'hard') {
                        $pdo->prepare("UPDATE email_contacts SET status='bounced' WHERE id=:id")
                            ->execute(['id' => $contact['id']]);
                        $pdo->prepare(
                            "INSERT INTO suppression_list (normalized_email, reason) VALUES (:email, 'hard_bounce')
                             ON DUPLICATE KEY UPDATE reason=IF(reason='unsubscribe','unsubscribe','hard_bounce')"
                        )->execute(['email' => $contact['normalized_email']]);
                        $pdo->prepare(
                            "UPDATE campaign_queue SET status='cancelled', completed_at=UTC_TIMESTAMP(),
                             last_error='Kontakt aufgrund eines Bounces gesperrt.'
                             WHERE contact_id=:contact AND status='queued'"
                        )->execute(['contact' => $contact['id']]);
                    }
                }
                $pdo->commit();
            }
            imap_setflag_full($imap, (string) $uid, '\\Seen', ST_UID);
            $processed++;
        }
        $pdo->prepare(
            'UPDATE bounce_mailboxes SET last_uid=:uid, last_checked_at=UTC_TIMESTAMP() WHERE id=1'
        )->execute(['uid' => $lastUid]);
    } catch (\Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    } finally {
        imap_close($imap);
    }
    return ['processed' => $processed, 'error' => null];
}
