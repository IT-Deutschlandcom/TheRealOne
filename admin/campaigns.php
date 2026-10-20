<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';
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
            (in_array($action, ['duplicate', 'archive'], true) && (!$id || $id === false))) {
            throw new RuntimeException('Ungültige Kampagne.');
        }
        if ($action === 'save') {
            $listId = filter_var($_POST['list_id'] ?? null, FILTER_VALIDATE_INT);
            $name = trim((string) ($_POST['name'] ?? ''));
            $fromName = trim((string) ($_POST['from_name'] ?? ''));
            $fromEmail = trim((string) ($_POST['from_email'] ?? ''));
            $replyTo = trim((string) ($_POST['reply_to'] ?? ''));
            $subject = trim((string) ($_POST['subject'] ?? ''));
            $html = (string) ($_POST['html_content'] ?? '');
            $text = (string) ($_POST['text_content'] ?? '');
            if (!$listId || text_length($name) < 1 || text_length($name) > 180 ||
                text_length($fromName) < 1 || text_length($fromName) > 160 ||
                !filter_var($fromEmail, FILTER_VALIDATE_EMAIL) || strlen($fromEmail) > 254 ||
                ($replyTo !== '' && (!filter_var($replyTo, FILTER_VALIDATE_EMAIL) || strlen($replyTo) > 254)) ||
                text_length($subject) < 1 || text_length($subject) > 255 ||
                strlen($html) > 1_000_000 || strlen($text) > 1_000_000) {
                throw new RuntimeException('Bitte prüfen Sie die Pflichtfelder und Eingabelängen.');
            }
            $listQuery = $pdo->prepare('SELECT id FROM email_lists WHERE id = :id');
            $listQuery->execute(['id' => $listId]);
            if (!$listQuery->fetchColumn()) {
                throw new RuntimeException('Die Empfängerliste existiert nicht.');
            }
            if ($id) {
                $update = $pdo->prepare(
                    "UPDATE campaigns SET list_id=:list, name=:name, from_name=:from_name,
                     from_email=:from_email, reply_to=:reply_to, subject=:subject,
                     html_content=:html, text_content=:text, updated_by=:admin
                     WHERE id=:id AND status='draft'"
                );
                $update->execute([
                    'list' => $listId, 'name' => $name, 'from_name' => $fromName,
                    'from_email' => $fromEmail, 'reply_to' => $replyTo ?: null,
                    'subject' => $subject, 'html' => $html, 'text' => $text,
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
                     (list_id, created_by, updated_by, name, from_name, from_email, reply_to, subject, html_content, text_content)
                     VALUES (:list, :created_by, :updated_by, :name, :from_name, :from_email, :reply_to, :subject, :html, :text)"
                );
                $insert->execute([
                    'list' => $listId, 'created_by' => $admin['id'], 'updated_by' => $admin['id'], 'name' => $name,
                    'from_name' => $fromName, 'from_email' => $fromEmail,
                    'reply_to' => $replyTo ?: null, 'subject' => $subject,
                    'html' => $html, 'text' => $text,
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
$campaigns = $pdo->query(
    "SELECT c.id, c.name, c.subject, c.status, c.updated_at, l.name AS list_name,
     (SELECT COUNT(*) FROM email_list_contacts lc
       JOIN email_contacts ec ON ec.id=lc.contact_id AND ec.status='active'
       WHERE lc.list_id=c.list_id
         AND EXISTS (SELECT 1 FROM contact_consents cc WHERE cc.contact_id=ec.id)
         AND NOT EXISTS (SELECT 1 FROM suppression_list sl WHERE sl.normalized_email=ec.normalized_email)
     ) AS eligible_contacts
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
    <p class="muted">Gespeicherte Inhalte werden nicht versendet. Vor einem Versand muss die gültige Versandberechtigung erneut geprüft werden. Es gibt hier keinen Start- oder Testversand.</p>
    <?php if (!$canEdit): ?><p class="muted">Ihre Rolle darf Kampagnen nur ansehen.</p><?php endif; ?>
    <?php if ($canEdit): ?>
    <form method="post" class="form-grid">
        <input type="hidden" name="csrf_token" value="<?= escape(csrf_token()) ?>">
        <input type="hidden" name="action" value="save">
        <?php if ($editing): ?><input type="hidden" name="campaign_id" value="<?= (int) $editing['id'] ?>"><?php endif; ?>
        <label>Kampagnenname<input name="name" maxlength="180" required value="<?= escape($editing['name'] ?? '') ?>"></label>
        <label>Empfängerliste<select name="list_id" required><option value="">Auswählen …</option><?php foreach ($lists as $list): ?><option value="<?= (int) $list['id'] ?>" <?= (int) ($editing['list_id'] ?? 0) === (int) $list['id'] ? 'selected' : '' ?>><?= escape($list['name']) ?></option><?php endforeach; ?></select></label>
        <div class="grid">
            <label>Absendername<input name="from_name" maxlength="160" required value="<?= escape($editing['from_name'] ?? '') ?>"></label>
            <label>Absenderadresse<input type="email" name="from_email" maxlength="254" required value="<?= escape($editing['from_email'] ?? '') ?>"></label>
            <label>Antwortadresse (optional)<input type="email" name="reply_to" maxlength="254" value="<?= escape($editing['reply_to'] ?? '') ?>"></label>
        </div>
        <label>Betreff<input name="subject" maxlength="255" required value="<?= escape($editing['subject'] ?? '') ?>"></label>
        <label>HTML-Inhalt<textarea name="html_content" maxlength="1000000"><?= escape($editing['html_content'] ?? '') ?></textarea></label>
        <label>Plain-Text-Alternative<textarea name="text_content" maxlength="1000000"><?= escape($editing['text_content'] ?? '') ?></textarea></label>
        <button type="submit">Entwurf speichern</button>
    </form>
    <?php endif; ?>
</section>
<section class="panel" style="margin-top:18px">
    <h2>Kampagnen</h2>
    <div class="table-wrap"><table><thead><tr><th>Name / Betreff</th><th>Liste</th><th>Status</th><th>Berechtigte Kontakte*</th><th>Geändert</th><th>Aktionen</th></tr></thead><tbody>
    <?php foreach ($campaigns as $campaign): ?><tr>
        <td><?= escape($campaign['name']) ?><br><span class="muted"><?= escape($campaign['subject']) ?></span></td>
        <td><?= escape($campaign['list_name']) ?></td><td><?= escape($campaign['status']) ?></td>
        <td><?= number_format((int) $campaign['eligible_contacts']) ?></td><td><?= escape($campaign['updated_at']) ?></td>
        <td><?php if ($canEdit): ?><div class="actions">
            <?php if ($campaign['status'] === 'draft'): ?><a class="button button-secondary" href="/admin/campaigns.php?edit=<?= (int) $campaign['id'] ?>">Bearbeiten</a><?php endif; ?>
            <form method="post"><input type="hidden" name="csrf_token" value="<?= escape(csrf_token()) ?>"><input type="hidden" name="campaign_id" value="<?= (int) $campaign['id'] ?>"><input type="hidden" name="action" value="duplicate"><button class="button-secondary" type="submit">Duplizieren</button></form>
            <?php if ($campaign['status'] === 'draft'): ?><form method="post" onsubmit="return confirm('Kampagne archivieren?');"><input type="hidden" name="csrf_token" value="<?= escape(csrf_token()) ?>"><input type="hidden" name="campaign_id" value="<?= (int) $campaign['id'] ?>"><input type="hidden" name="action" value="archive"><button class="button-danger" type="submit">Archivieren</button></form><?php endif; ?>
        </div><?php else: ?><span class="muted">Nur Ansicht</span><?php endif; ?></td>
    </tr><?php endforeach; ?>
    <?php if ($campaigns === []): ?><tr><td colspan="6" class="muted">Noch keine Kampagnenentwürfe.</td></tr><?php endif; ?>
    </tbody></table></div>
    <p class="muted">* Zählung aktiver Kontakte mit dokumentiertem Nachweis und ohne zentrale Suppression. Die tatsächliche Versandberechtigung muss vor jedem Versand geprüft werden.</p>
</section>
<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
