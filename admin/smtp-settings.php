<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/Mailer.php';
$admin = require_auth(['superadmin', 'admin']);
$error = '';
$message = '';
$testResult = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? '');
    try {
        if ($action === 'test') {
            $profile = \MailMarketing\active_smtp_profile($pdo, $config);
            if (!$profile) {
                throw new RuntimeException('Speichern und aktivieren Sie zuerst ein SMTP-Profil.');
            }
            $testResult = \MailMarketing\test_smtp_connection($profile);
            $pdo->prepare(
                'UPDATE smtp_profiles SET last_test_at=UTC_TIMESTAMP(), last_test_success=:success WHERE id=:id'
            )->execute(['success' => $testResult['ok'] ? 1 : 0, 'id' => $profile['id']]);
            log_activity($pdo, (int) $admin['id'], 'smtp.connection_test', ['success' => $testResult['ok']]);
        } elseif ($action === 'save') {
            \MailMarketing\encryption_key($config);
            $host = trim((string) ($_POST['host'] ?? ''));
            $port = filter_var($_POST['port'] ?? null, FILTER_VALIDATE_INT);
            $encryption = (string) ($_POST['encryption'] ?? '');
            $username = trim((string) ($_POST['username'] ?? ''));
            $password = (string) ($_POST['password'] ?? '');
            $fromEmail = trim((string) ($_POST['from_email'] ?? ''));
            $fromName = trim((string) ($_POST['from_name'] ?? ''));
            $timeout = filter_var($_POST['timeout_seconds'] ?? 20, FILTER_VALIDATE_INT);
            $proxyValue = (string) ($_POST['proxy_id'] ?? '');
            $proxyId = $proxyValue === '' ? null : filter_var($proxyValue, FILTER_VALIDATE_INT);
            if ($proxyId !== null && (!$proxyId || $proxyId < 1)) {
                throw new RuntimeException('Ungültige Proxy-Auswahl.');
            }
            if (!preg_match('/^(?=.{1,253}$)[A-Za-z0-9](?:[A-Za-z0-9.-]*[A-Za-z0-9])?$/', $host) ||
                !$port || $port > 65535 || !in_array($encryption, ['tls', 'ssl', 'none'], true) ||
                !filter_var($fromEmail, FILTER_VALIDATE_EMAIL) || strlen($fromEmail) > 254 ||
                text_length($fromName) < 1 || text_length($fromName) > 160 ||
                !$timeout || $timeout > 60 || strlen($password) > 2000 || strlen($username) > 254) {
                throw new RuntimeException('Bitte prüfen Sie Hostname, Port, Absender und Zeitlimit.');
            }
            if (($encryption === 'none' && $username !== '') || ($username === '' && $password !== '')) {
                throw new RuntimeException('SMTP-Anmeldedaten sind nur mit TLS/SSL zulässig; ohne Benutzername darf kein Passwort gespeichert werden.');
            }
            $pdo->beginTransaction();
            $existing = $pdo->query('SELECT * FROM smtp_profiles ORDER BY id LIMIT 1 FOR UPDATE')->fetch();
            if ((int) ($existing['proxy_id'] ?? 0) !== (int) $proxyId) {
                $running = $pdo->query("SELECT id FROM campaigns WHERE status IN ('queued','sending','paused') LIMIT 1 FOR UPDATE")->fetchColumn();
                if ($running) {
                    throw new RuntimeException('Der feste Proxy kann erst geändert werden, wenn alle laufenden oder pausierten Kampagnen beendet oder abgebrochen sind.');
                }
            }
            if ($proxyId !== null) {
                $proxyQuery = $pdo->prepare('SELECT id FROM proxies WHERE id=:id AND is_active=1');
                $proxyQuery->execute(['id' => $proxyId]);
                if (!$proxyQuery->fetchColumn()) {
                    throw new RuntimeException('Der ausgewählte Proxy ist nicht verfügbar.');
                }
            }
            $encrypted = $username === '' ? null : ($existing['password_encrypted'] ?? null);
            if ($password !== '') {
                $encrypted = \MailMarketing\encrypt_secret($password, $config);
            }
            if ($username !== '' && $encrypted === null) {
                throw new RuntimeException('Geben Sie das SMTP-Passwort ein.');
            }
            $pdo->exec('UPDATE smtp_profiles SET is_active=0');
            if ($existing) {
                $save = $pdo->prepare(
                    'UPDATE smtp_profiles SET name=:name, host=:host, port=:port, encryption=:encryption,
                     username=:username, password_encrypted=:password, from_email=:from_email,
                     from_name=:from_name, timeout_seconds=:timeout, proxy_id=:proxy, is_active=1,
                     last_test_at=NULL, last_test_success=NULL, updated_by=:admin WHERE id=:id'
                );
                $save->execute([
                    'name' => 'Standard', 'host' => $host, 'port' => $port, 'encryption' => $encryption,
                    'username' => $username ?: null, 'password' => $encrypted, 'from_email' => $fromEmail,
                    'from_name' => $fromName, 'timeout' => $timeout, 'proxy' => $proxyId, 'admin' => $admin['id'], 'id' => $existing['id'],
                ]);
            } else {
                $save = $pdo->prepare(
                    'INSERT INTO smtp_profiles
                     (name, host, port, encryption, username, password_encrypted, from_email, from_name, timeout_seconds, proxy_id, is_active, last_test_at, last_test_success, updated_by)
                     VALUES (:name, :host, :port, :encryption, :username, :password, :from_email, :from_name, :timeout, :proxy, 1, NULL, NULL, :admin)'
                );
                $save->execute([
                    'name' => 'Standard', 'host' => $host, 'port' => $port, 'encryption' => $encryption,
                    'username' => $username ?: null, 'password' => $encrypted, 'from_email' => $fromEmail,
                    'from_name' => $fromName, 'timeout' => $timeout, 'proxy' => $proxyId, 'admin' => $admin['id'],
                ]);
            }
            log_activity($pdo, (int) $admin['id'], 'smtp.profile_updated');
            $pdo->commit();
            $message = 'SMTP-Profil sicher gespeichert und aktiviert.';
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
        error_log('SMTP settings operation failed: ' . $exception->getMessage());
        $error = 'Die SMTP-Aktion konnte nicht abgeschlossen werden. Details stehen im Serverprotokoll.';
    }
}

$profile = $pdo->query('SELECT * FROM smtp_profiles ORDER BY id LIMIT 1')->fetch() ?: [];
$proxies = $pdo->query(
    'SELECT p.id,p.host,p.port,p.is_active,g.name AS group_name
     FROM proxies p LEFT JOIN proxy_groups g ON g.id=p.group_id ORDER BY g.name,p.host,p.port'
)->fetchAll();
$keyReady = true;
try {
    \MailMarketing\encryption_key($config);
} catch (RuntimeException $exception) {
    $keyReady = false;
    $error = $error ?: $exception->getMessage();
}
$pageTitle = 'SMTP Settings';
require dirname(__DIR__) . '/includes/header.php';
?>
<?php if ($message !== ''): ?><div class="alert"><?= escape($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="alert alert-error"><?= escape($error) ?></div><?php endif; ?>
<?php if ($testResult !== null): ?><div class="alert <?= $testResult['ok'] ? '' : 'alert-error' ?>"><?= $testResult['ok'] ? 'Verbindung erfolgreich' : 'Verbindung fehlgeschlagen' ?> · <?= (int) $testResult['elapsed_ms'] ?> ms · <?= escape($testResult['message']) ?></div><?php endif; ?>
<section class="panel">
    <h2>Aktives SMTP-Profil</h2>
    <p class="muted">Letzter Verbindungstest: <?= !empty($profile['last_test_at']) ? escape($profile['last_test_at']) . (!empty($profile['last_test_success']) ? ' · erfolgreich' : ' · fehlgeschlagen') : 'noch nicht durchgeführt' ?></p>
    <?php if (!$keyReady): ?><div class="alert alert-error">SMTP-Konfiguration ist gesperrt, bis ein sicherer Verschlüsselungsschlüssel in config.php hinterlegt ist.</div><?php endif; ?>
    <p class="muted">Das Passwort wird mit AES-256-GCM verschlüsselt gespeichert und nie erneut im Formular angezeigt. TLS-Zertifikate werden validiert. Verwenden Sie nur einen SMTP-Server, für den Sie ausdrücklich autorisiert sind.</p>
    <p class="muted">SMTP-TLS/SSL funktioniert unabhängig davon, ob das Panel über HTTP oder HTTPS geöffnet wird. Das gilt auch bei einem fest zugewiesenen SOCKS5-Proxy; ein HTTPS-Zertifikat für das Panel ist für SMTP-TLS nicht erforderlich.</p>
    <form method="post" class="form-grid">
        <input type="hidden" name="csrf_token" value="<?= escape(csrf_token()) ?>">
        <input type="hidden" name="action" value="save">
        <label>SMTP-Hostname<input name="host" maxlength="253" required value="<?= escape($profile['host'] ?? '') ?>"></label>
        <label>Fester SOCKS5-Proxy<select name="proxy_id">
            <option value="">Kein Proxy (direkte Verbindung)</option>
            <?php foreach ($proxies as $proxy): ?>
                <option value="<?= (int) $proxy['id'] ?>" <?= (int) ($profile['proxy_id'] ?? 0) === (int) $proxy['id'] ? 'selected' : '' ?>><?= escape(($proxy['group_name'] ? $proxy['group_name'] . ' · ' : '') . $proxy['host'] . ':' . $proxy['port']) ?><?= !(int) $proxy['is_active'] ? ' (inaktiv, nicht verwendbar)' : '' ?></option>
            <?php endforeach; ?>
        </select></label>
        <p class="muted">Proxys im Proxy Manager importieren. Die Zuordnung bleibt während laufender und pausierter Kampagnen unverändert. Bei Proxyfehlern gibt es keinen direkten Fallback. Nach einer Änderung das Profil erneut testen. SOCKS5 verschlüsselt die Proxy-Anmeldung nicht; nur vertrauenswürdige oder zusätzlich abgesicherte Proxy-Verbindungen verwenden. SMTP-TLS bleibt bis zum SMTP-Server aktiv.</p>
        <div class="grid">
            <label>Port<input type="number" name="port" min="1" max="65535" required value="<?= escape($profile['port'] ?? 587) ?>"></label>
            <label>Verschlüsselung<select name="encryption"><option value="tls" <?= ($profile['encryption'] ?? 'tls') === 'tls' ? 'selected' : '' ?>>STARTTLS</option><option value="ssl" <?= ($profile['encryption'] ?? '') === 'ssl' ? 'selected' : '' ?>>SSL/TLS</option><option value="none" <?= ($profile['encryption'] ?? '') === 'none' ? 'selected' : '' ?>>Keine (nur internes, vertrauenswürdiges Relay)</option></select></label>
            <label>Timeout (Sekunden)<input type="number" name="timeout_seconds" min="5" max="60" value="<?= escape($profile['timeout_seconds'] ?? 20) ?>" required></label>
        </div>
        <label>SMTP-Benutzername<input name="username" maxlength="254" autocomplete="off" value="<?= escape($profile['username'] ?? '') ?>"></label>
        <label>SMTP-Passwort<input type="password" name="password" maxlength="2000" autocomplete="new-password" placeholder="<?= !empty($profile['password_encrypted']) ? 'Gespeichert – leer lassen, um es beizubehalten' : 'Passwort' ?>"></label>
        <div class="grid">
            <label>Absenderadresse<input type="email" name="from_email" maxlength="254" required value="<?= escape($profile['from_email'] ?? '') ?>"></label>
            <label>Absendername<input name="from_name" maxlength="160" required value="<?= escape($profile['from_name'] ?? '') ?>"></label>
        </div>
        <div class="actions"><button type="submit" <?= !$keyReady ? 'disabled' : '' ?>>Profil speichern</button></div>
    </form>
    <form method="post" class="spaced-top-small">
        <input type="hidden" name="csrf_token" value="<?= escape(csrf_token()) ?>"><input type="hidden" name="action" value="test">
        <button class="button-secondary" type="submit">Test SMTP Connection</button>
    </form>
</section>
<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
