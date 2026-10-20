<?php
declare(strict_types=1);

function escape(string|int|null $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function text_length(string $value): int
{
    $length = preg_match_all('/./us', $value);
    return $length === false ? strlen($value) : $length;
}

function csrf_token(): string
{
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf(): void
{
    $provided = $_POST['csrf_token'] ?? '';
    if (!is_string($provided) || !hash_equals(csrf_token(), $provided)) {
        http_response_code(400);
        exit('Ungültige Anfrage. Bitte laden Sie die Seite neu.');
    }
}

function redirect(string $location): never
{
    header('Location: ' . $location, true, 303);
    exit;
}

function log_activity(PDO $pdo, ?int $adminId, string $action, array $metadata = []): void
{
    $statement = $pdo->prepare(
        'INSERT INTO admin_activity_logs (admin_id, action, metadata_json, ip_address, created_at)
         VALUES (:admin_id, :action, :metadata, :ip, UTC_TIMESTAMP())'
    );
    $statement->execute([
        'admin_id' => $adminId,
        'action' => $action,
        'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR),
        'ip' => substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
    ]);
}
