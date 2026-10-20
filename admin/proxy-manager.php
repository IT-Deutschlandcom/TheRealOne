<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/Mailer.php';
$admin = require_auth(['superadmin', 'admin']);
$error = '';
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    try {
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'add') {
            \MailMarketing\encryption_key($config);
            $host = strtolower(trim((string) ($_POST['host'] ?? '')));
            $port = filter_var($_POST['port'] ?? null, FILTER_VALIDATE_INT);
            $username = trim((string) ($_POST['username'] ?? ''));
            $password = (string) ($_POST['password'] ?? '');
            $notes = trim((string) ($_POST['notes'] ?? ''));
            $groupId = filter_var($_POST['group_id'] ?? null, FILTER_VALIDATE_INT);
            $validHost = filter_var($host, FILTER_VALIDATE_IP) ||
                filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME);
            if (!$validHost || !$port || $port > 65535 || strlen($username) > 254 ||
                strlen($password) > 2000 || text_length($notes) > 500) {
                throw new RuntimeException('Proxy-Adresse, Port oder optionale Felder sind ungültig.');
            }
            $encrypted = $password === '' ? null : \MailMarketing\encrypt_secret($password, $config);
            $insert = $pdo->prepare(
                'INSERT INTO proxies (group_id, host, port, username, password_encrypted, notes)
                 VALUES (:group_id,:host,:port,:username,:password,:notes)'
            );
            $insert->execute([
                'group_id' => $groupId ?: null, 'host' => $host, 'port' => $port,
                'username' => $username ?: null, 'password' => $encrypted, 'notes' => $notes ?: null,
            ]);
            log_activity($pdo, (int) $admin['id'], 'proxy.inventory_added');
            $message = 'Autorisierter SOCKS5-Endpunkt zur Inventarliste hinzugefügt.';
        } elseif ($action === 'group') {
            $name = trim((string) ($_POST['name'] ?? ''));
            if ($name === '' || text_length($name) > 120) {
                throw new RuntimeException('Gruppenname ist ungültig.');
            }
            $pdo->prepare('INSERT INTO proxy_groups (name) VALUES (:name)')->execute(['name' => $name]);
            $message = 'Gruppe erstellt.';
        } elseif ($action === 'delete') {
            $id = filter_var($_POST['proxy_id'] ?? null, FILTER_VALIDATE_INT);
            if (!$id) {
                throw new RuntimeException('Proxy nicht gefunden.');
            }
            $pdo->prepare('DELETE FROM proxies WHERE id=:id')->execute(['id' => $id]);
            log_activity($pdo, (int) $admin['id'], 'proxy.inventory_deleted');
            $message = 'Proxy aus der Inventarliste entfernt.';
        } else {
            throw new RuntimeException('Unbekannte Aktion.');
        }
    } catch (RuntimeException $exception) {
        $error = $exception->getMessage();
    } catch (Throwable $exception) {
        error_log('Proxy inventory operation failed: ' . $exception->getMessage());
        $error = 'Proxy-Inventaraktion fehlgeschlagen.';
    }
}
$groups = $pdo->query('SELECT id, name FROM proxy_groups ORDER BY name')->fetchAll();
$proxies = $pdo->query(
    'SELECT p.id,p.host,p.port,p.is_active,p.notes,g.name AS group_name
     FROM proxies p LEFT JOIN proxy_groups g ON g.id=p.group_id ORDER BY p.host,p.port'
)->fetchAll();
$pageTitle = 'Proxy Manager';
require dirname(__DIR__) . '/includes/header.php';
?>
<?php if ($message !== ''): ?><div class="alert"><?= escape($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="alert alert-error"><?= escape($error) ?></div><?php endif; ?>
<div class="alert">Nur eigene oder ausdrücklich autorisierte SOCKS5-Endpunkte verwalten. Dieser sichere Modus prüft Format und Duplikate, aber führt keine Netzwerk- oder Exit-IP-Prüfungen aus und routet niemals Kampagnen über Proxys.</div>
<section class="panel"><h2>Gruppe anlegen</h2><form method="post" class="inline-form"><input type="hidden" name="csrf_token" value="<?= escape(csrf_token()) ?>"><input type="hidden" name="action" value="group"><label class="flex-grow">Name<input name="name" maxlength="120" required></label><button type="submit">Gruppe anlegen</button></form></section>
<section class="panel spaced-top"><h2>Autorisierten Proxy registrieren</h2><form method="post" class="form-grid"><input type="hidden" name="csrf_token" value="<?= escape(csrf_token()) ?>"><input type="hidden" name="action" value="add"><label>Hostname oder IP<input name="host" maxlength="253" required></label><div class="grid"><label>SOCKS5-Port<input type="number" name="port" min="1" max="65535" value="1080" required></label><label>Gruppe<select name="group_id"><option value="">Keine</option><?php foreach ($groups as $group): ?><option value="<?= (int) $group['id'] ?>"><?= escape($group['name']) ?></option><?php endforeach; ?></select></label></div><label>Benutzername (optional)<input name="username" maxlength="254"></label><label>Passwort (optional)<input type="password" name="password" autocomplete="new-password"></label><label>Notiz<input name="notes" maxlength="500"></label><button type="submit">Speichern</button></form></section>
<section class="panel spaced-top"><h2>Proxy-Inventar</h2><div class="table-wrap"><table><thead><tr><th>Endpoint</th><th>Protokoll</th><th>Gruppe</th><th>Netzwerktest</th><th>Notiz</th><th>Aktion</th></tr></thead><tbody>
<?php foreach ($proxies as $proxy): ?><tr><td><?= escape($proxy['host']) ?>:<?= (int) $proxy['port'] ?></td><td>SOCKS5</td><td><?= escape($proxy['group_name'] ?? '–') ?></td><td>Nicht durchgeführt (keine Netzwerkprüfung aktiviert)</td><td><?= escape($proxy['notes'] ?? '') ?></td><td><form method="post" data-confirm="Proxy aus der Liste löschen?"><input type="hidden" name="csrf_token" value="<?= escape(csrf_token()) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="proxy_id" value="<?= (int) $proxy['id'] ?>"><button class="button-danger" type="submit">Löschen</button></form></td></tr><?php endforeach; ?>
<?php if ($proxies === []): ?><tr><td colspan="6" class="muted">Keine Proxys registriert.</td></tr><?php endif; ?>
</tbody></table></div></section>
<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
