<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/Mailer.php';
$admin = require_auth();
$canEdit = in_array($admin['role'], ['superadmin', 'admin', 'campaign_manager'], true);
$error = '';
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (!$canEdit) {
        http_response_code(403);
        exit('Keine Berechtigung.');
    }
    $action = (string) ($_POST['action'] ?? '');
    try {
        $campaignId = $_POST['campaign_id'] ?? null;
        $id = ($campaignId === null || $campaignId === '') ? null : filter_var($campaignId, FILTER_VALIDATE_INT);
        if (($action === 'save' && $id === false) ||
            (in_array($action, ['duplicate', 'archive', 'start', 'pause', 'resume', 'cancel', 'interval'], true) && (!$id || $id === false))) {
            throw new RuntimeException('Ungültige Kampagne.');
        }
        if ($action === 'start') {
            \MailMarketing\unsubscribe_url(1, $config);
            $pdo->beginTransaction();
            $smtpProfile = \MailMarketing\active_smtp_profile($pdo, $config, true);
            if (!$smtpProfile || (int) ($smtpProfile['last_test_success'] ?? 0) !== 1) {
                throw new RuntimeException('Konfigurieren und testen Sie zuerst ein SMTP-Profil.');
            }
            $interval = filter_var($_POST['send_interval_seconds'] ?? null, FILTER_VALIDATE_INT);
            if (!$interval || $interval < 60 || $interval > 86400) {
                throw new RuntimeException('Das Sendeintervall muss zwischen 60 und 86400 Sekunden liegen.');
            }
            $campaignQuery = $pdo->prepare("SELECT id, list_id, status, from_email, html_content, text_content FROM campaigns WHERE id=:id FOR UPDATE");
            $campaignQuery->execute(['id' => $id]);
            $campaign = $campaignQuery->fetch();
            if (!$campaign || $campaign['status'] !== 'draft') {
                throw new RuntimeException('Nur Kampagnenentwürfe können gestartet werden.');
            }
            if (!str_contains($campaign['html_content'], '{{unsubscribe_url}}') ||
                !str_contains($campaign['text_content'], '{{unsubscribe_url}}')) {
                throw new RuntimeException('HTML- und Plain-Text-Inhalt benötigen jeweils {{unsubscribe_url}}.');
            }
            if (strcasecmp($campaign['from_email'], (string) $smtpProfile['from_email']) !== 0) {
                throw new RuntimeException('Die Kampagnen-Absenderadresse muss dem aktivierten SMTP-Profil entsprechen.');
            }
            $enqueue = $pdo->prepare(
                "INSERT IGNORE INTO campaign_queue (campaign_id, contact_id, available_at)
                 SELECT :campaign_id, ec.id, UTC_TIMESTAMP()
                 FROM email_list_contacts lc
                 JOIN email_contacts ec ON ec.id=lc.contact_id AND ec.status='active'
                 WHERE lc.list_id=:list_id
                   AND EXISTS (
                       SELECT 1 FROM contact_consents cc
                       WHERE cc.contact_id=ec.id AND cc.granted_at<=UTC_TIMESTAMP() AND cc.evidence_source<>''
                   )
                   AND NOT EXISTS (
                       SELECT 1 FROM suppression_list sl WHERE sl.normalized_email=ec.normalized_email
                   )"
            );
            $enqueue->execute(['campaign_id' => $id, 'list_id' => $campaign['list_id']]);
            $queuedCount = $enqueue->rowCount();
            if ($queuedCount === 0) {
                throw new RuntimeException('Keine aktiven Kontakte mit dokumentierter Versandgrundlage und ohne Sperre gefunden.');
            }
            $start = $pdo->prepare(
                "UPDATE campaigns SET status='sending', send_interval_seconds=:interval,
                 next_send_at=UTC_TIMESTAMP(), updated_by=:admin WHERE id=:id AND status='draft'"
            );
            $start->execute(['interval' => $interval, 'admin' => $admin['id'], 'id' => $id]);
            log_activity($pdo, (int) $admin['id'], 'campaign.started', ['campaign_id' => $id, 'queued' => $queuedCount, 'interval_seconds' => $interval]);
            $pdo->commit();
            $message = $queuedCount . ' Empfänger in die Versandwarteschlange aufgenommen.';
        } elseif ($action === 'pause') {
            $stmt = $pdo->prepare("UPDATE campaigns SET status='paused', updated_by=:admin WHERE id=:id AND status='sending'");
            $stmt->execute(['admin' => $admin['id'], 'id' => $id]);
            if ($stmt->rowCount() !== 1) {
                throw new RuntimeException('Kampagne läuft nicht oder wurde nicht gefunden.');
            }
            log_activity($pdo, (int) $admin['id'], 'campaign.paused', ['campaign_id' => $id]);
            $message = 'Kampagne pausiert. Eine bereits laufende SMTP-Übertragung kann noch abgeschlossen werden.';
        } elseif ($action === 'resume') {
            $stmt = $pdo->prepare("UPDATE campaigns SET status='sending', next_send_at=UTC_TIMESTAMP(), updated_by=:admin WHERE id=:id AND status='paused'");
            $stmt->execute(['admin' => $admin['id'], 'id' => $id]);
            if ($stmt->rowCount() !== 1) {
                throw new RuntimeException('Kampagne ist nicht pausiert oder wurde nicht gefunden.');
            }
            log_activity($pdo, (int) $admin['id'], 'campaign.resumed', ['campaign_id' => $id]);
            $message = 'Kampagne fortgesetzt.';
        } elseif ($action === 'cancel') {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("UPDATE campaigns SET status='cancelled', updated_by=:admin WHERE id=:id AND status IN ('sending','paused')");
            $stmt->execute(['admin' => $admin['id'], 'id' => $id]);
            if ($stmt->rowCount() !== 1) {
                throw new RuntimeException('Kampagne kann nicht abgebrochen werden.');
            }
            $pdo->prepare("UPDATE campaign_queue SET status='cancelled', completed_at=UTC_TIMESTAMP() WHERE campaign_id=:id AND status='queued'")
                ->execute(['id' => $id]);
            log_activity($pdo, (int) $admin['id'], 'campaign.cancelled', ['campaign_id' => $id]);
            $pdo->commit();
            $message = 'Kampagne abgebrochen. Bereits laufende SMTP-Übertragungen können noch abgeschlossen werden.';
        } elseif ($action === 'interval') {
            $interval = filter_var($_POST['send_interval_seconds'] ?? null, FILTER_VALIDATE_INT);
            if (!$interval || $interval < 60 || $interval > 86400) {
                throw new RuntimeException('Das Intervall muss zwischen 60 und 86400 Sekunden liegen.');
            }
            $campaignState = $pdo->prepare('SELECT status FROM campaigns WHERE id=:id');
            $campaignState->execute(['id' => $id]);
            $status = $campaignState->fetchColumn();
            if (!in_array($status, ['sending', 'paused'], true)) {
                throw new RuntimeException('Das Intervall kann nur bei laufenden oder pausierten Kampagnen geändert werden.');
            }
            $lastDelivery = $pdo->prepare(
                "SELECT MAX(completed_at) FROM campaign_queue
                 WHERE campaign_id=:id AND status IN ('accepted','failed','unknown')"
            );
            $lastDelivery->execute(['id' => $id]);
            $lastAt = $lastDelivery->fetchColumn();
            $dueAt = gmdate('Y-m-d H:i:s', max(time(), $lastAt ? strtotime($lastAt . ' UTC') + $interval : time()));
            $updateInterval = $pdo->prepare(
                'UPDATE campaigns SET send_interval_seconds=:interval, next_send_at=:due_at, updated_by=:admin WHERE id=:id'
            );
            $updateInterval->execute(['interval' => $interval, 'due_at' => $dueAt, 'admin' => $admin['id'], 'id' => $id]);
            log_activity($pdo, (int) $admin['id'], 'campaign.interval_changed', ['campaign_id' => $id, 'interval_seconds' => $interval]);
            $message = 'Sendeintervall aktualisiert. Die Änderung gilt für die folgenden Queue-Schritte.';
        } elseif ($action === 'save') {
            $listId = filter_var($_POST['list_id'] ?? null, FILTER_VALIDATE_INT);
            $name = trim((string) ($_POST['name'] ?? ''));
            $fromName = trim((string) ($_POST['from_name'] ?? ''));
            $fromEmail = trim((string) ($_POST['from_email'] ?? ''));
            $replyTo = trim((string) ($_POST['reply_to'] ?? ''));
            $subject = trim((string) ($_POST['subject'] ?? ''));
            $html = (string) ($_POST['html_content'] ?? '');
            $text = (string) ($_POST['text_content'] ?? '');
            $interval = filter_var($_POST['send_interval_seconds'] ?? 180, FILTER_VALIDATE_INT);
            $templateId = filter_var($_POST['template_id'] ?? null, FILTER_VALIDATE_INT);
            if (!$listId || text_length($name) < 1 || text_length($name) > 180 ||
                text_length($fromName) < 1 || text_length($fromName) > 160 ||
                !filter_var($fromEmail, FILTER_VALIDATE_EMAIL) || strlen($fromEmail) > 254 ||
                ($replyTo !== '' && (!filter_var($replyTo, FILTER_VALIDATE_EMAIL) || strlen($replyTo) > 254)) ||
                text_length($subject) < 1 || text_length($subject) > 255 ||
                strlen($html) > 1_000_000 || strlen($text) > 1_000_000 ||
                !str_contains($html, '{{unsubscribe_url}}') || !str_contains($text, '{{unsubscribe_url}}') ||
                !$interval || $interval < 60 || $interval > 86400) {
                throw new RuntimeException('Bitte prüfen Sie die Pflichtfelder, Eingabelängen und Abmeldelinks in beiden E-Mail-Formaten.');
            }
            $listQuery = $pdo->prepare('SELECT id FROM email_lists WHERE id = :id');
            $listQuery->execute(['id' => $listId]);
            if (!$listQuery->fetchColumn()) {
                throw new RuntimeException('Die Empfängerliste existiert nicht.');
            }
            $templateId = $templateId ?: null;
            if ($templateId !== null) {
                $templateQuery = $pdo->prepare('SELECT id FROM email_templates WHERE id=:id');
                $templateQuery->execute(['id' => $templateId]);
                if (!$templateQuery->fetchColumn()) {
                    throw new RuntimeException('Die ausgewählte Vorlage existiert nicht.');
                }
            }
            if ($id) {
                $update = $pdo->prepare(
                    "UPDATE campaigns SET list_id=:list, name=:name, from_name=:from_name,
                     from_email=:from_email, reply_to=:reply_to, subject=:subject,
                     html_content=:html, text_content=:text, template_id=:template_id,
                     send_interval_seconds=:interval, updated_by=:admin
                     WHERE id=:id AND status='draft'"
                );
                $update->execute([
                    'list' => $listId, 'name' => $name, 'from_name' => $fromName,
                    'from_email' => $fromEmail, 'reply_to' => $replyTo ?: null,
                    'subject' => $subject, 'html' => $html, 'text' => $text,
                    'template_id' => $templateId, 'interval' => $interval,
                    'admin' => $admin['id'], 'id' => $id,
                ]);
                if ($update->rowCount() === 0) {
                    $check = $pdo->prepare("SELECT status FROM campaigns WHERE id=:id");
                    $check->execute(['id' => $id]);
                    if ($check->fetchColumn() !== 'draft') {
                        throw new RuntimeException('Kampagne nicht gefunden oder bereits archiviert.');
                    }
                }
                log_activity($pdo, (int) $admin['id'], 'campaign.updated', ['campaign_id' => $id]);
                $message = 'Entwurf gespeichert.';
            } else {
                $insert = $pdo->prepare(
                    "INSERT INTO campaigns
                     (list_id, template_id, created_by, updated_by, name, from_name, from_email, reply_to, subject, html_content, text_content, send_interval_seconds)
                     VALUES (:list, :template_id, :created_by, :updated_by, :name, :from_name, :from_email, :reply_to, :subject, :html, :text, :interval)"
                );
                $insert->execute([
                    'list' => $listId, 'template_id' => $templateId,
                    'created_by' => $admin['id'], 'updated_by' => $admin['id'], 'name' => $name,
                    'from_name' => $fromName, 'from_email' => $fromEmail,
                    'reply_to' => $replyTo ?: null, 'subject' => $subject,
                    'html' => $html, 'text' => $text, 'interval' => $interval,
                ]);
                $id = (int) $pdo->lastInsertId();
                log_activity($pdo, (int) $admin['id'], 'campaign.created', ['campaign_id' => $id]);
                $message = 'Entwurf erstellt.';
            }
        } elseif ($action === 'duplicate') {
            $copy = $pdo->prepare(
                "INSERT INTO campaigns (list_id, created_by, updated_by, name, from_name, from_email, reply_to,
                 subject, html_content, text_content)
             SELECT list_id, :created_by, :updated_by, CONCAT(LEFT(name, 160), ' (Kopie)'), from_name, from_email,
                 reply_to, subject, html_content, text_content FROM campaigns WHERE id=:id"
            );
            $copy->execute(['created_by' => $admin['id'], 'updated_by' => $admin['id'], 'id' => $id]);
            if ($copy->rowCount() !== 1) {
                throw new RuntimeException('Kampagne nicht gefunden.');
            }
            $newId = (int) $pdo->lastInsertId();
            log_activity($pdo, (int) $admin['id'], 'campaign.duplicated', ['campaign_id' => $id, 'copy_id' => $newId]);
            $message = 'Kampagne als Entwurf dupliziert.';
        } elseif ($action === 'archive') {
            $archive = $pdo->prepare(
                "UPDATE campaigns SET status='archived', updated_by=:admin WHERE id=:id AND status='draft'"
            );
            $archive->execute(['admin' => $admin['id'], 'id' => $id]);
            if ($archive->rowCount() !== 1) {
                throw new RuntimeException('Kampagne nicht gefunden oder bereits archiviert.');
            }
            log_activity($pdo, (int) $admin['id'], 'campaign.archived', ['campaign_id' => $id]);
            $message = 'Kampagne archiviert.';
        } else {
            throw new RuntimeException('Unbekannte Aktion.');
        }
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($exception instanceof RuntimeException) {
            $error = $exception->getMessage();
        } else {
            error_log('Campaign operation failed: ' . $exception->getMessage());
            $error = 'Die Kampagnenaktion konnte nicht abgeschlossen werden.';
        }
    }
}

$editing = null;
$editId = filter_input(INPUT_GET, 'edit', FILTER_VALIDATE_INT);
if ($editId) {
    $statement = $pdo->prepare("SELECT * FROM campaigns WHERE id=:id AND status='draft'");
    $statement->execute(['id' => $editId]);
    $editing = $statement->fetch() ?: null;
}
$lists = $pdo->query('SELECT id, name FROM email_lists ORDER BY name')->fetchAll();
$templates = $pdo->query('SELECT id, name, subject FROM email_templates ORDER BY name')->fetchAll();
$campaigns = $pdo->query(
    "SELECT c.id, c.name, c.subject, c.status, c.updated_at, c.send_interval_seconds, l.name AS list_name,
     (SELECT COUNT(*) FROM email_list_contacts lc
       JOIN email_contacts ec ON ec.id=lc.contact_id AND ec.status='active'
       WHERE lc.list_id=c.list_id
         AND EXISTS (SELECT 1 FROM contact_consents cc WHERE cc.contact_id=ec.id)
         AND NOT EXISTS (SELECT 1 FROM suppression_list sl WHERE sl.normalized_email=ec.normalized_email)
     ) AS eligible_contacts,
     (SELECT COUNT(*) FROM campaign_queue q WHERE q.campaign_id=c.id AND q.status='queued') AS pending_count,
     (SELECT COUNT(*) FROM campaign_queue q WHERE q.campaign_id=c.id AND q.status='accepted') AS accepted_count,
     (SELECT COUNT(*) FROM campaign_queue q WHERE q.campaign_id=c.id AND q.status IN ('failed','unknown')) AS failed_count
     FROM campaigns c JOIN email_lists l ON l.id=c.list_id
     ORDER BY c.updated_at DESC"
)->fetchAll();
$pageTitle = 'Campaign Manager';
require dirname(__DIR__) . '/includes/header.php';
?>
<?php if ($message !== ''): ?><div class="alert"><?= escape($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="alert alert-error"><?= escape($error) ?></div><?php endif; ?>
<section class="panel">
    <h2><?= $editing ? 'Entwurf bearbeiten' : 'Kampagnenentwurf erstellen' ?></h2>
    <p class="muted">Versand nur an aktive Kontakte mit dokumentiertem Nachweis und ohne Sperre. Der Windows-Worker prüft diese Bedingungen direkt vor jeder SMTP-Übertragung erneut.</p>
    <?php if (!$canEdit): ?><p class="muted">Ihre Rolle darf Kampagnen nur ansehen.</p><?php endif; ?>
    <?php if ($canEdit): ?>
    <form method="post" class="form-grid">
        <input type="hidden" name="csrf_token" value="<?= escape(csrf_token()) ?>">
        <input type="hidden" name="action" value="save">
        <?php if ($editing): ?><input type="hidden" name="campaign_id" value="<?= (int) $editing['id'] ?>"><?php endif; ?>
        <label>Kampagnenname<input name="name" maxlength="180" required value="<?= escape($editing['name'] ?? '') ?>"></label>
        <label>Empfängerliste<select name="list_id" required><option value="">Auswählen …</option><?php foreach ($lists as $list): ?><option value="<?= (int) $list['id'] ?>" <?= (int) ($editing['list_id'] ?? 0) === (int) $list['id'] ? 'selected' : '' ?>><?= escape($list['name']) ?></option><?php endforeach; ?></select></label>
        <label>Vorlage optional<select id="campaign-template" name="template_id"><option value="">Ohne Vorlage</option><?php foreach ($templates as $template): ?><option value="<?= (int) $template['id'] ?>" <?= (int) ($editing['template_id'] ?? 0) === (int) $template['id'] ? 'selected' : '' ?>><?= escape($template['name']) ?> — <?= escape($template['subject']) ?></option><?php endforeach; ?></select></label>
        <div class="grid">
            <label>Absendername<input name="from_name" maxlength="160" required value="<?= escape($editing['from_name'] ?? '') ?>"></label>
            <label>Absenderadresse<input type="email" name="from_email" maxlength="254" required value="<?= escape($editing['from_email'] ?? '') ?>"></label>
            <label>Antwortadresse (optional)<input type="email" name="reply_to" maxlength="254" value="<?= escape($editing['reply_to'] ?? '') ?>"></label>
        </div>
        <label>Betreff<input name="subject" maxlength="255" required value="<?= escape($editing['subject'] ?? '') ?>"></label>
        <label>Intervall zwischen E-Mails (Sekunden; 60–86400)<input type="number" name="send_interval_seconds" min="60" max="86400" value="<?= escape($editing['send_interval_seconds'] ?? 180) ?>" required></label>
        <label>HTML-Inhalt (muss `{{unsubscribe_url}}` enthalten)<textarea name="html_content" maxlength="1000000" required><?= escape($editing['html_content'] ?? '') ?></textarea></label>
        <label>Plain-Text-Alternative (muss `{{unsubscribe_url}}` enthalten)<textarea name="text_content" maxlength="1000000" required><?= escape($editing['text_content'] ?? '') ?></textarea></label>
        <button type="submit">Entwurf speichern</button>
    </form>
    <script src="/assets/js/campaign-template.js" defer></script>
    <?php endif; ?>
</section>
<section class="panel spaced-top">
    <h2>Kampagnen</h2>
    <div class="table-wrap"><table><thead><tr><th>Name / Betreff</th><th>Liste</th><th>Status</th><th>Warteschlange</th><th>Geändert</th><th>Aktionen</th></tr></thead><tbody>
    <?php foreach ($campaigns as $campaign): ?><tr>
        <td><?= escape($campaign['name']) ?><br><span class="muted"><?= escape($campaign['subject']) ?><br>Intervall: <?= (int) $campaign['send_interval_seconds'] ?> s</span></td>
        <td><?= escape($campaign['list_name']) ?></td><td><?= escape($campaign['status']) ?></td>
        <td><?= number_format((int) $campaign['pending_count']) ?> ausstehend · <?= number_format((int) $campaign['accepted_count']) ?> angenommen · <?= number_format((int) $campaign['failed_count']) ?> Fehler/unklar</td><td><?= escape($campaign['updated_at']) ?></td>
        <td><?php if ($canEdit): ?><div class="actions">
            <?php if ($campaign['status'] === 'draft'): ?><a class="button button-secondary" href="/admin/campaigns.php?edit=<?= (int) $campaign['id'] ?>">Bearbeiten</a><?php endif; ?>
            <?php if (in_array($campaign['status'], ['sending','paused','completed'], true)): ?><a class="button button-secondary" href="/admin/campaign-monitor.php?id=<?= (int) $campaign['id'] ?>">Monitor</a><?php endif; ?>
            <?php if ($campaign['status'] === 'sending'): ?><form method="post"><input type="hidden" name="csrf_token" value="<?= escape(csrf_token()) ?>"><input type="hidden" name="campaign_id" value="<?= (int) $campaign['id'] ?>"><input type="hidden" name="action" value="pause"><button class="button-secondary" type="submit">Pause</button></form><?php endif; ?>
            <?php if ($campaign['status'] === 'paused'): ?><form method="post"><input type="hidden" name="csrf_token" value="<?= escape(csrf_token()) ?>"><input type="hidden" name="campaign_id" value="<?= (int) $campaign['id'] ?>"><input type="hidden" name="action" value="resume"><button type="submit">Fortsetzen</button></form><?php endif; ?>
            <?php if (in_array($campaign['status'], ['sending','paused'], true)): ?><form method="post" class="inline-form"><input type="hidden" name="csrf_token" value="<?= escape(csrf_token()) ?>"><input type="hidden" name="campaign_id" value="<?= (int) $campaign['id'] ?>"><input type="hidden" name="action" value="interval"><input type="number" aria-label="Intervall in Sekunden" name="send_interval_seconds" min="60" max="86400" value="<?= (int) $campaign['send_interval_seconds'] ?>" required><button class="button-secondary" type="submit">Intervall ändern</button></form><?php endif; ?>
            <?php if ($campaign['status'] === 'draft'): ?><form method="post" data-confirm="Versand an berechtigte Kontakte starten?"><input type="hidden" name="csrf_token" value="<?= escape(csrf_token()) ?>"><input type="hidden" name="campaign_id" value="<?= (int) $campaign['id'] ?>"><input type="hidden" name="action" value="start"><input type="hidden" name="send_interval_seconds" value="<?= (int) $campaign['send_interval_seconds'] ?>"><button type="submit">Start</button></form><?php endif; ?>
            <?php if (in_array($campaign['status'], ['sending','paused'], true)): ?><form method="post" data-confirm="Kampagne abbrechen?"><input type="hidden" name="csrf_token" value="<?= escape(csrf_token()) ?>"><input type="hidden" name="campaign_id" value="<?= (int) $campaign['id'] ?>"><input type="hidden" name="action" value="cancel"><button class="button-danger" type="submit">Abbrechen</button></form><?php endif; ?>
            <form method="post"><input type="hidden" name="csrf_token" value="<?= escape(csrf_token()) ?>"><input type="hidden" name="campaign_id" value="<?= (int) $campaign['id'] ?>"><input type="hidden" name="action" value="duplicate"><button class="button-secondary" type="submit">Duplizieren</button></form>
            <?php if ($campaign['status'] === 'draft'): ?><form method="post" data-confirm="Kampagne archivieren?"><input type="hidden" name="csrf_token" value="<?= escape(csrf_token()) ?>"><input type="hidden" name="campaign_id" value="<?= (int) $campaign['id'] ?>"><input type="hidden" name="action" value="archive"><button class="button-danger" type="submit">Archivieren</button></form><?php endif; ?>
        </div><?php else: ?><span class="muted">Nur Ansicht</span><?php endif; ?></td>
    </tr><?php endforeach; ?>
    <?php if ($campaigns === []): ?><tr><td colspan="6" class="muted">Noch keine Kampagnenentwürfe.</td></tr><?php endif; ?>
    </tbody></table></div>
    <p class="muted">Das Intervall ist die Mindestzeit zwischen zwei E-Mails einer Kampagne. Die Windows-Aufgabe prüft mindestens jede Minute. SMTP-Annahme ist keine garantierte Zustellung.</p>
</section>
<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
