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
        if (in_array($action, ['add', 'import'], true)) {
            \MailMarketing\encryption_key($config);
            $protocol = (string) ($_POST['protocol'] ?? 'socks5');
            if (!in_array($protocol, ['socks5', 'http', 'https'], true)) {
                throw new RuntimeException('Bitte SOCKS5, HTTP oder HTTPS als Proxy-Protokoll auswählen.');
            }
            $groupValue = (string) ($_POST['group_id'] ?? '');
            $groupId = $groupValue === '' ? null : filter_var($groupValue, FILTER_VALIDATE_INT);
            if ($groupId !== null) {
                $groupQuery = $pdo->prepare('SELECT id FROM proxy_groups WHERE id=:id');
                $groupQuery->execute(['id' => $groupId]);
                if (!$groupId || !$groupQuery->fetchColumn()) {
                    throw new RuntimeException('Die ausgewählte Proxy-Gruppe existiert nicht.');
                }
            }
        }
        if ($action === 'add') {
            $host = strtolower(trim((string) ($_POST['host'] ?? '')));
            $port = filter_var($_POST['port'] ?? null, FILTER_VALIDATE_INT);
            $username = trim((string) ($_POST['username'] ?? ''));
            $password = (string) ($_POST['password'] ?? '');
            $notes = trim((string) ($_POST['notes'] ?? ''));
            \MailMarketing\validate_proxy($host, (int) $port, $username, $password, $protocol);
            if (text_length($notes) > 500) {
                throw new RuntimeException('Die Notiz darf maximal 500 Zeichen enthalten.');
            }
            if (filter_var($host, FILTER_VALIDATE_IP)) {
                $host = inet_ntop(inet_pton($host));
            }
            $encrypted = $password === '' ? null : \MailMarketing\encrypt_secret($password, $config);
            $insert = $pdo->prepare(
                'INSERT INTO proxies (group_id, host, port, protocol, username, password_encrypted, notes)
                 VALUES (:group_id,:host,:port,:protocol,:username,:password,:notes)'
            );
            $insert->execute([
                'group_id' => $groupId ?: null, 'host' => $host, 'port' => $port,
                'protocol' => $protocol,
                'username' => $username ?: null, 'password' => $encrypted, 'notes' => $notes ?: null,
            ]);
            log_activity($pdo, (int) $admin['id'], 'proxy.inventory_added');
            $message = 'Autorisierter ' . strtoupper($protocol) . '-Endpunkt zur Inventarliste hinzugefügt.';
        } elseif ($action === 'import') {
            $raw = (string) ($_POST['proxies'] ?? '');
            if (trim($raw) === '' || strlen($raw) > 2_000_000) {
                throw new RuntimeException('Geben Sie eine Proxy-Liste mit maximal 2 MB ein.');
            }
            $lines = preg_split('/\r\n|\r|\n/', $raw);
            $counts = ['imported' => 0, 'duplicate' => 0, 'invalid' => 0];
            $invalidLines = [];
            $pdo->beginTransaction();
            $insert = $pdo->prepare(
                'INSERT INTO proxies (group_id, host, port, protocol, username, password_encrypted)
                 VALUES (:group_id,:host,:port,:protocol,:username,:password)
                 ON DUPLICATE KEY UPDATE id=id'
            );
            foreach ($lines as $index => $line) {
                if (trim($line) === '') {
                    continue;
                }
                try {
                    $proxy = \MailMarketing\parse_proxy_line($line, $protocol);
                } catch (RuntimeException $exception) {
                    $counts['invalid']++;
                    if (count($invalidLines) < 10) {
                        $invalidLines[] = $index + 1;
                    }
                    continue;
                }
                $insert->execute([
                    'group_id' => $groupId, 'host' => $proxy['host'], 'port' => $proxy['port'],
                    'protocol' => $proxy['protocol'],
                    'username' => $proxy['username'],
                    'password' => \MailMarketing\encrypt_secret($proxy['password'], $config),
                ]);
                $counts[$insert->rowCount() === 1 ? 'imported' : 'duplicate']++;
            }
            log_activity($pdo, (int) $admin['id'], 'proxy.imported', $counts);
            $pdo->commit();
            $message = sprintf('Import abgeschlossen: %d aufgenommen, %d Duplikate, %d ungültig.',
                $counts['imported'], $counts['duplicate'], $counts['invalid']);
            if ($invalidLines !== []) {
                $error = 'Ungültiges Format oder ungültige Zugangsdaten in Zeile(n): ' . implode(', ', $invalidLines) .
                    ($counts['invalid'] > 10 ? ' (erste 10 Fehler).' : '.');
            }
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
            $pdo->beginTransaction();
            $pdo->prepare('SELECT id FROM proxies WHERE id=:id FOR UPDATE')->execute(['id' => $id]);
            $assigned = $pdo->prepare('SELECT id FROM smtp_profiles WHERE proxy_id=:id');
            $assigned->execute(['id' => $id]);
            if ($assigned->fetchColumn()) {
                throw new RuntimeException('Dieser Proxy ist einem SMTP-Profil zugewiesen und kann nicht gelöscht werden.');
            }
            $pdo->prepare('DELETE FROM proxies WHERE id=:id')->execute(['id' => $id]);
            log_activity($pdo, (int) $admin['id'], 'proxy.inventory_deleted');
            $pdo->commit();
            $message = 'Proxy aus der Inventarliste entfernt.';
        } else {
            throw new RuntimeException('Unbekannte Aktion.');
        }
    } catch (RuntimeException $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($exception instanceof PDOException) {
            error_log('Proxy inventory database operation failed: ' . $exception->getMessage());
            $error = 'Proxy-Aktion fehlgeschlagen. Prüfen Sie Duplikate und SMTP-Zuordnungen.';
        } else {
            $error = $exception->getMessage();
        }
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Proxy inventory operation failed: ' . $exception->getMessage());
        $error = 'Proxy-Inventaraktion fehlgeschlagen.';
    }
}
$groups = $pdo->query('SELECT id, name FROM proxy_groups ORDER BY name')->fetchAll();
$proxies = $pdo->query(
    'SELECT p.id,p.host,p.port,p.protocol,p.is_active,p.notes,g.name AS group_name
     FROM proxies p LEFT JOIN proxy_groups g ON g.id=p.group_id ORDER BY p.host,p.port'
)->fetchAll();
$pageTitle = 'Proxy Manager';
require dirname(__DIR__) . '/includes/header.php';
?>
<?php if ($message !== ''): ?><div class="alert"><?= escape($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="alert alert-error"><?= escape($error) ?></div><?php endif; ?>
<div class="alert">Nur eigene oder ausdrücklich autorisierte SOCKS5-, HTTP- oder HTTPS-Endpunkte verwalten. HTTP/HTTPS benötigen CONNECT-Freigabe zum SMTP-Port und unterstützen keine oder Basic-Anmeldung. HTTPS prüft zusätzlich das Proxy-Zertifikat. Unter SMTP Settings kann ein fester Proxy zugewiesen werden. Es gibt keine automatische Rotation. Der SMTP-Verbindungstest prüft den zugewiesenen Proxy; separate Exit-IP-Prüfungen finden nicht statt.</div>
<section class="panel"><h2>Gruppe anlegen</h2><form method="post" class="inline-form"><input type="hidden" name="csrf_token" value="<?= escape(csrf_token()) ?>"><input type="hidden" name="action" value="group"><label class="flex-grow">Name<input name="name" maxlength="120" required></label><button type="submit">Gruppe anlegen</button></form></section>
<section class="panel spaced-top"><h2>Proxy-Massenimport</h2>
<p class="muted">Protokoll für die gesamte Liste auswählen. Ein Proxy pro Zeile: user:password@ip:port (IPv6: user:password@[2001:db8::1]:1080). Maximal 2 MB. Gleiche Protokoll/IP/Port-Kombinationen werden als Duplikate übersprungen; vorhandene Zugangsdaten bleiben unverändert. Passwörter werden verschlüsselt gespeichert.</p>
<form method="post" class="form-grid" autocomplete="off">
<input type="hidden" name="csrf_token" value="<?= escape(csrf_token()) ?>"><input type="hidden" name="action" value="import">
<label>Proxy-Protokoll<select name="protocol"><option value="socks5">SOCKS5</option><option value="http">HTTP (CONNECT)</option><option value="https">HTTPS (TLS + CONNECT)</option></select></label>
<label>Gruppe<select name="group_id"><option value="">Keine</option><?php foreach ($groups as $group): ?><option value="<?= (int) $group['id'] ?>"><?= escape($group['name']) ?></option><?php endforeach; ?></select></label>
<label>Proxy-Liste<textarea name="proxies" rows="10" maxlength="2000000" required placeholder="user:password@192.0.2.1:1080" spellcheck="false"></textarea></label>
<button type="submit">Proxys importieren</button>
</form></section>
<section class="panel spaced-top"><h2>Autorisierten Proxy registrieren</h2><form method="post" class="form-grid"><input type="hidden" name="csrf_token" value="<?= escape(csrf_token()) ?>"><input type="hidden" name="action" value="add"><label>Proxy-Protokoll<select name="protocol"><option value="socks5">SOCKS5</option><option value="http">HTTP (CONNECT)</option><option value="https">HTTPS (TLS + CONNECT)</option></select></label><label>Hostname oder IP<input name="host" maxlength="253" required></label><div class="grid"><label>Proxy-Port<input type="number" name="port" min="1" max="65535" value="1080" required></label><label>Gruppe<select name="group_id"><option value="">Keine</option><?php foreach ($groups as $group): ?><option value="<?= (int) $group['id'] ?>"><?= escape($group['name']) ?></option><?php endforeach; ?></select></label></div><label>Benutzername (optional, zusammen mit Passwort)<input name="username" maxlength="254"></label><label>Passwort (optional, maximal 255 Bytes)<input type="password" name="password" maxlength="255" autocomplete="new-password"></label><label>Notiz<input name="notes" maxlength="500"></label><button type="submit">Speichern</button></form></section>
<section class="panel spaced-top"><h2>Proxy-Inventar</h2><div class="table-wrap"><table><thead><tr><th>Endpoint</th><th>Protokoll</th><th>Gruppe</th><th>Netzwerktest</th><th>Notiz</th><th>Aktion</th></tr></thead><tbody>
<?php foreach ($proxies as $proxy): ?><tr><td><?= escape($proxy['host']) ?>:<?= (int) $proxy['port'] ?></td><td><?= escape(strtoupper($proxy['protocol'])) ?></td><td><?= escape($proxy['group_name'] ?? '–') ?></td><td>Nicht durchgeführt (keine Netzwerkprüfung aktiviert)</td><td><?= escape($proxy['notes'] ?? '') ?></td><td><form method="post" data-confirm="Proxy aus der Liste löschen?"><input type="hidden" name="csrf_token" value="<?= escape(csrf_token()) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="proxy_id" value="<?= (int) $proxy['id'] ?>"><button class="button-danger" type="submit">Löschen</button></form></td></tr><?php endforeach; ?>
<?php if ($proxies === []): ?><tr><td colspan="6" class="muted">Keine Proxys registriert.</td></tr><?php endif; ?>
</tbody></table></div></section>
<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
