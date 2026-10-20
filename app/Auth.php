<?php
declare(strict_types=1);

function current_admin(): ?array
{
    return $_SESSION['admin'] ?? null;
}

function require_auth(array $roles = []): array
{
    $admin = current_admin();
    if ($admin === null) {
        redirect('/login.php');
    }
    if ($roles !== [] && !in_array($admin['role'], $roles, true)) {
        http_response_code(403);
        exit('Keine Berechtigung.');
    }

    $timeout = (int) ($GLOBALS['config']['app']['session_timeout'] ?? 1800);
    if (time() - (int) ($_SESSION['last_activity'] ?? 0) > $timeout) {
        logout_admin();
        redirect('/login.php?expired=1');
    }
    $_SESSION['last_activity'] = time();
    return $admin;
}

function login_admin(PDO $pdo, string $email, string $password): bool
{
    $query = $pdo->prepare(
        'SELECT a.id, a.email, a.password_hash, r.name AS role
         FROM admins a JOIN roles r ON r.id = a.role_id
         WHERE a.email = :email AND a.is_active = 1 LIMIT 1'
    );
    $query->execute(['email' => strtolower(trim($email))]);
    $admin = $query->fetch();
    if (!$admin || !password_verify($password, $admin['password_hash'])) {
        return false;
    }

    session_regenerate_id(true);
    $_SESSION['admin'] = [
        'id' => (int) $admin['id'],
        'email' => $admin['email'],
        'role' => $admin['role'],
    ];
    $_SESSION['last_activity'] = time();
    unset($_SESSION['csrf_token']);
    $pdo->prepare('UPDATE admins SET last_login_at = UTC_TIMESTAMP() WHERE id = :id')
        ->execute(['id' => $admin['id']]);
    $pdo->prepare('DELETE FROM admin_login_attempts WHERE ip_hash = :ip_hash')
        ->execute(['ip_hash' => login_ip_hash()]);
    log_activity($pdo, (int) $admin['id'], 'admin.login');
    return true;
}

function login_ip_hash(): string
{
    return hash('sha256', (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
}

function logout_admin(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => $params['path'],
            'domain' => $params['domain'],
            'secure' => $params['secure'],
            'httponly' => $params['httponly'],
            'samesite' => 'Lax',
        ]);
    }
    session_destroy();
}
