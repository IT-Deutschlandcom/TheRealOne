<?php
declare(strict_types=1);
require_once __DIR__ . '/app/bootstrap.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('POST erforderlich.');
}
verify_csrf();
$admin = current_admin();
if ($admin !== null) {
    log_activity($pdo, (int) $admin['id'], 'admin.logout');
}
logout_admin();
redirect('/login.php');
