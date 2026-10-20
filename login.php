<?php
declare(strict_types=1);
require_once __DIR__ . '/app/bootstrap.php';
if (current_admin() !== null) {
    redirect('/admin/dashboard.php');
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $ipHash = login_ip_hash();
    $pdo->prepare('DELETE FROM admin_login_attempts WHERE attempted_at < UTC_TIMESTAMP() - INTERVAL 15 MINUTE')
        ->execute();
    $rate = $pdo->prepare(
        'SELECT COUNT(*) FROM admin_login_attempts
         WHERE ip_hash = :ip_hash AND attempted_at >= UTC_TIMESTAMP() - INTERVAL 15 MINUTE'
    );
    $rate->execute(['ip_hash' => $ipHash]);
    if ((int) $rate->fetchColumn() >= 8) {
        $error = 'Zu viele Anmeldeversuche. Bitte warten Sie 15 Minuten.';
    } elseif (login_admin($pdo, (string) ($_POST['email'] ?? ''), (string) ($_POST['password'] ?? ''))) {
        redirect('/admin/dashboard.php');
    } else {
        $pdo->prepare('INSERT INTO admin_login_attempts (ip_hash, attempted_at) VALUES (:ip_hash, UTC_TIMESTAMP())')
            ->execute(['ip_hash' => $ipHash]);
        $error = 'Anmeldung fehlgeschlagen.';
        try {
            log_activity($pdo, null, 'admin.login_failed');
        } catch (Throwable $exception) {
            error_log('Failed login audit could not be stored.');
        }
    }
}
?>
<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Anmelden</title><link rel="stylesheet" href="/assets/css/app.css"></head>
<body class="auth-page"><main class="panel auth-card"><p class="eyebrow">Sicherer Zugang</p><h1>Anmelden</h1>
<?php if (isset($_GET['installed'])): ?><div class="alert">Administrator erstellt. Sie können sich jetzt anmelden.</div><?php endif; ?>
<?php if (isset($_GET['expired'])): ?><div class="alert">Ihre Sitzung ist abgelaufen. Bitte melden Sie sich erneut an.</div><?php endif; ?>
<?php if ($error !== ''): ?><div class="alert alert-error"><?= escape($error) ?></div><?php endif; ?>
<form method="post" class="form-grid">
<input type="hidden" name="csrf_token" value="<?= escape(csrf_token()) ?>">
<label>E-Mail-Adresse<input type="email" name="email" autocomplete="username" required></label>
<label>Passwort<input type="password" name="password" autocomplete="current-password" required></label>
<button type="submit">Anmelden</button>
</form></main></body></html>
