<?php
declare(strict_types=1);
require_once __DIR__ . '/app/bootstrap.php';

$count = (int) $pdo->query('SELECT COUNT(*) FROM admins')->fetchColumn();
if ($count > 0) {
    http_response_code(403);
    exit('Die Installation wurde bereits abgeschlossen.');
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $email = trim((string) ($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 12) {
        $error = 'Geben Sie eine gültige E-Mail-Adresse und ein Passwort mit mindestens 12 Zeichen ein.';
    } else {
        try {
            $pdo->beginTransaction();
            $insert = $pdo->prepare(
                'INSERT INTO admins (role_id, email, password_hash, is_active) VALUES (1, :email, :hash, 1)'
            );
            $insert->execute([
                'email' => strtolower($email),
                'hash' => password_hash($password, PASSWORD_DEFAULT),
            ]);
            $id = (int) $pdo->lastInsertId();
            log_activity($pdo, $id, 'admin.install');
            $pdo->commit();
            redirect('/login.php?installed=1');
        } catch (PDOException $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Initial admin setup failed: ' . $exception->getMessage());
            $error = 'Administrator konnte nicht erstellt werden. Prüfen Sie die Datenbank und versuchen Sie es erneut.';
        }
    }
}
?>
<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Installation</title><link rel="stylesheet" href="/assets/css/app.css"></head>
<body class="auth-page"><main class="panel auth-card"><p class="eyebrow">Ersteinrichtung</p><h1>Administrator erstellen</h1>
<?php if ($error !== ''): ?><div class="alert alert-error"><?= escape($error) ?></div><?php endif; ?>
<form method="post" class="form-grid">
<input type="hidden" name="csrf_token" value="<?= escape(csrf_token()) ?>">
<label>E-Mail-Adresse<input type="email" name="email" autocomplete="email" required></label>
<label>Passwort (mindestens 12 Zeichen)<input type="password" name="password" autocomplete="new-password" minlength="12" required></label>
<button type="submit">Administrator anlegen</button>
</form><p class="muted">Vor dem Aufruf müssen Sie <code>database.sql</code> importiert und <code>config.php</code> konfiguriert haben.</p></main></body></html>
