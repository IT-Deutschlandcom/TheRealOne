<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/Mailer.php';
require_once dirname(__DIR__) . '/app/BounceManager.php';
$admin = require_auth(['superadmin', 'admin']);
$error = '';
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    try {
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'save') {
            \MailMarketing\encryption_key($config);
            $host = trim((string) ($_POST['host'] ?? ''));
            $port = filter_var($_POST['port'] ?? null, FILTER_VALIDATE_INT);
            $encryption = (string) ($_POST['encryption'] ?? '');
            $username = trim((string) ($_POST['username'] ?? ''));
            $password = (string) ($_POST['password'] ?? '');
            $folder = trim((string) ($_POST['folder_name'] ?? 'INBOX'));
            $enabled = isset($_POST['is_active']) ? 1 : 0;
            if (!preg_match('/^(?=.{1,253}$)[A-Za-z0-9](?:[A-Za-z0-9.-]*[A-Za-z0-9])?$/', $host) ||
                !$port || $port > 65535 || !in_array($encryption, ['ssl', 'tls'], true) ||
                !filter_var($username, FILTER_VALIDATE_EMAIL) || strlen($username) > 254 ||
                strlen($password) > 2000 || !preg_match('/^[A-Za-z0-9._ -]{1,120}$/', $folder)) {
                throw new RuntimeException('Bitte prüfen Sie Mailserver, Zugang und Ordnerangaben.');
            }
            $existing = $pdo->query('SELECT * FROM bounce_mailboxes WHERE id=1')->fetch();
            $encrypted = $password !== ''
                ? \MailMarketing\encrypt_secret($password, $config)
                : ($existing['password_encrypted'] ?? null);
            if ($encrypted === null) {
                throw new RuntimeException('Geben Sie das Passwort des Rücklaufpostfachs ein.');
            }
            $save = $pdo->prepare(
                'INSERT INTO bounce_mailboxes
                 (id, host, port, encryption, username, password_encrypted, folder_name, last_uid, is_active, updated_by)
                 VALUES (1, :host, :port, :encryption, :username, :password, :folder, 0, :active, :admin)
                 ON DUPLICATE KEY UPDATE host=VALUES(host), port=VALUES(port), encryption=VALUES(encryption),
                 username=VALUES(username), password_encrypted=VALUES(password_encrypted),
                 folder_name=VALUES(folder_name), is_active=VALUES(is_active), updated_by=VALUES(updated_by)'
            );
            $save->execute([
                'host' => $host, 'port' => $port, 'encryption' => $encryption, 'username' => $username,
                'password' => $encrypted, 'folder' => $folder, 'active' => $enabled, 'admin' => $admin['id'],
            ]);
            log_activity($pdo, (int) $admin['id'], 'bounce_mailbox.updated', ['active' => (bool) $enabled]);
            $message = 'Bounce-Postfach gespeichert.';
        } elseif ($action === 'check') {
            $result = \MailMarketing\process_bounces($pdo, $config, 50);
            if ($result['error'] !== null) {
                throw new RuntimeException($result['error']);
            }
            log_activity($pdo, (int) $admin['id'], 'bounce_mailbox.checked', ['processed' => $result['processed']]);
            $message = (int) $result['processed'] . ' neue E-Mails geprüft.';
        } else {
            throw new RuntimeException('Unbekannte Aktion.');
        }
    } catch (RuntimeException $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = $exception->getMessage();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Bounce mailbox action failed: ' . $exception->getMessage());
        $error = 'Bounce-Postfach konnte nicht verarbeitet werden.';
    }
}
$mailbox = $pdo->query('SELECT * FROM bounce_mailboxes WHERE id=1')->fetch() ?: [];
$events = $pdo->query(
    'SELECT b.received_at, b.bounce_type, b.smtp_status, b.diagnostic, c.email
     FROM bounce_events b LEFT JOIN email_contacts c ON c.id=b.contact_id
     ORDER BY b.received_at DESC LIMIT 100'
)->fetchAll();
$pageTitle = 'Bounce Management';
require dirname(__DIR__) . '/includes/header.php';
?>
<?php if ($message !== ''): ?><div class="alert"><?= escape($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="alert alert-error"><?= escape($error) ?></div><?php endif; ?>
<?php if (!extension_loaded('imap')): ?><div class="alert alert-error">Die PHP-IMAP-Erweiterung ist auf diesem Server nicht aktiv. Bounce-Abholung bleibt bis zur Installation/ Aktivierung deaktiviert.</div><?php endif; ?>
<section class="panel">
    <h2>Rücklaufpostfach</h2>
    <p class="muted">Der Worker prüft nach jedem Lauf höchstens 50 neue Nachrichten. Harte Bounces werden sofort gesperrt; Soft-Bounces werden nach drei Ereignissen innerhalb von 30 Tagen gesperrt. Zugangsdaten werden verschlüsselt gespeichert.</p>
    <form method="post" class="form-grid">
        <input type="hidden" name="csrf_token" value="<?= escape(csrf_token()) ?>"><input type="hidden" name="action" value="save">
        <label>IMAP-Hostname<input name="host" maxlength="253" required value="<?= escape($mailbox['host'] ?? '') ?>"></label>
        <div class="grid">
            <label>Port<input type="number" name="port" min="1" max="65535" required value="<?= escape($mailbox['port'] ?? 993) ?>"></label>
            <label>Verschlüsselung<select name="encryption"><option value="ssl" <?= ($mailbox['encryption'] ?? 'ssl') === 'ssl' ? 'selected' : '' ?>>IMAPS / SSL</option><option value="tls" <?= ($mailbox['encryption'] ?? '') === 'tls' ? 'selected' : '' ?>>STARTTLS</option></select></label>
            <label>Ordner<input name="folder_name" maxlength="120" value="<?= escape($mailbox['folder_name'] ?? 'INBOX') ?>" required></label>
        </div>
        <label>Postfach-E-Mail<input type="email" name="username" maxlength="254" required value="<?= escape($mailbox['username'] ?? '') ?>"></label>
        <label>Passwort<input type="password" name="password" autocomplete="new-password" placeholder="<?= !empty($mailbox['password_encrypted']) ? 'Gespeichert – leer lassen, um es beizubehalten' : 'Passwort' ?>"></label>
        <label><span><input class="inline-checkbox" type="checkbox" name="is_active" <?= !empty($mailbox['is_active']) ? 'checked' : '' ?>> Abholung aktivieren</span></label>
        <button type="submit" <?= !extension_loaded('imap') ? 'disabled' : '' ?>>Speichern</button>
    </form>
    <form method="post" class="spaced-top-small"><input type="hidden" name="csrf_token" value="<?= escape(csrf_token()) ?>"><input type="hidden" name="action" value="check"><button class="button-secondary" type="submit" <?= !extension_loaded('imap') ? 'disabled' : '' ?>>Jetzt Bounce-Postfach prüfen</button></form>
    <p class="muted">Letzte Prüfung: <?= escape($mailbox['last_checked_at'] ?? 'noch nicht geprüft') ?></p>
</section>
<section class="panel spaced-top"><h2>Letzte Bounce-Ereignisse</h2><div class="table-wrap"><table><thead><tr><th>Zeit</th><th>Adresse</th><th>Typ</th><th>SMTP-Status</th><th>Diagnose</th></tr></thead><tbody>
<?php foreach ($events as $event): ?><tr><td><?= escape($event['received_at']) ?></td><td><?= escape($event['email'] ?? 'Kontakt gelöscht') ?></td><td><?= escape($event['bounce_type']) ?></td><td><?= escape($event['smtp_status'] ?? '–') ?></td><td><?= escape($event['diagnostic'] ?? '–') ?></td></tr><?php endforeach; ?>
<?php if ($events === []): ?><tr><td colspan="5" class="muted">Noch keine Bounces erfasst.</td></tr><?php endif; ?>
</tbody></table></div></section>
<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
