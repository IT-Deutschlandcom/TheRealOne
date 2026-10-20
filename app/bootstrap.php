<?php
declare(strict_types=1);

$GLOBALS['config'] = require dirname(__DIR__) . '/config.php';
date_default_timezone_set($GLOBALS['config']['app']['timezone'] ?? 'UTC');

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Security.php';
require_once __DIR__ . '/Auth.php';

$https = isset($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off';
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => $https,
    'httponly' => true,
    'samesite' => 'Lax',
]);
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
session_start();

try {
    $pdo = Database::connection($GLOBALS['config']);
} catch (Throwable $exception) {
    error_log('Database connection failed: ' . $exception->getMessage());
    http_response_code(503);
    exit('Datenbank nicht verfügbar. Bitte prüfen Sie die Installation und Konfiguration.');
}
