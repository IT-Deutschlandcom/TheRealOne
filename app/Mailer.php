<?php
declare(strict_types=1);

namespace MailMarketing;

use PHPMailer\PHPMailer\PHPMailer;
use RuntimeException;

require_once dirname(__DIR__) . '/assets/vendor/phpmailer/Exception.php';
require_once dirname(__DIR__) . '/assets/vendor/phpmailer/SMTP.php';
require_once dirname(__DIR__) . '/assets/vendor/phpmailer/PHPMailer.php';

function encryption_key(array $config): string
{
    $encoded = (string) ($config['app']['encryption_key'] ?? '');
    $key = ctype_xdigit($encoded) ? hex2bin($encoded) : false;
    if (!is_string($key) || strlen($key) !== 32) {
        throw new RuntimeException('Setzen Sie app.encryption_key in config.php auf 64 zufällige Hex-Zeichen.');
    }
    return $key;
}

function encrypt_secret(string $secret, array $config): string
{
    $iv = random_bytes(12);
    $tag = '';
    $ciphertext = openssl_encrypt($secret, 'aes-256-gcm', encryption_key($config), OPENSSL_RAW_DATA, $iv, $tag);
    if ($ciphertext === false) {
        throw new RuntimeException('SMTP-Passwort konnte nicht sicher gespeichert werden.');
    }
    return base64_encode($iv . $tag . $ciphertext);
}

function decrypt_secret(?string $encrypted, array $config): string
{
    if ($encrypted === null || $encrypted === '') {
        return '';
    }
    $payload = base64_decode($encrypted, true);
    if ($payload === false || strlen($payload) < 29) {
        throw new RuntimeException('Gespeicherte SMTP-Zugangsdaten sind ungültig.');
    }
    $secret = openssl_decrypt(
        substr($payload, 28),
        'aes-256-gcm',
        encryption_key($config),
        OPENSSL_RAW_DATA,
        substr($payload, 0, 12),
        substr($payload, 12, 16)
    );
    if (!is_string($secret)) {
        throw new RuntimeException('SMTP-Passwort kann nicht entschlüsselt werden. Prüfen Sie config.php.');
    }
    return $secret;
}

function active_smtp_profile(\PDO $pdo, array $config): ?array
{
    $profile = $pdo->query('SELECT * FROM smtp_profiles WHERE is_active=1 ORDER BY id LIMIT 1')->fetch();
    if (!$profile) {
        return null;
    }
    $profile['password'] = decrypt_secret($profile['password_encrypted'], $config);
    return $profile;
}

function create_mailer(array $profile): PHPMailer
{
    $mailer = new PHPMailer(true);
    $mailer->isSMTP();
    $mailer->Host = $profile['host'];
    $mailer->Port = (int) $profile['port'];
    $mailer->SMTPAuth = trim((string) $profile['username']) !== '';
    $mailer->Username = (string) $profile['username'];
    $mailer->Password = (string) $profile['password'];
    $mailer->Timeout = (int) $profile['timeout_seconds'];
    $mailer->SMTPKeepAlive = true;
    $mailer->SMTPDebug = 0;
    $mailer->CharSet = PHPMailer::CHARSET_UTF8;
    $mailer->SMTPOptions = [
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'allow_self_signed' => false,
        ],
    ];
    if ($profile['encryption'] === 'tls') {
        $mailer->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    } elseif ($profile['encryption'] === 'ssl') {
        $mailer->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
    } elseif ($profile['encryption'] !== 'none') {
        throw new RuntimeException('Unbekannte SMTP-Verschlüsselung.');
    }
    return $mailer;
}

function test_smtp_connection(array $profile): array
{
    $mailer = create_mailer($profile);
    $startedAt = microtime(true);
    try {
        $connected = $mailer->smtpConnect();
        $elapsed = (int) round((microtime(true) - $startedAt) * 1000);
        if (!$connected) {
            $mailer->smtpClose();
            return ['ok' => false, 'elapsed_ms' => $elapsed, 'message' => 'SMTP-Verbindung oder Authentifizierung fehlgeschlagen.'];
        }
        $reply = (string) $mailer->getSMTPInstance()->getLastReply();
        $mailer->smtpClose();
        return ['ok' => true, 'elapsed_ms' => $elapsed, 'message' => substr(preg_replace('/[\r\n]+/', ' ', $reply), 0, 180)];
    } catch (\Throwable $exception) {
        $mailer->smtpClose();
        return ['ok' => false, 'elapsed_ms' => (int) round((microtime(true) - $startedAt) * 1000), 'message' => 'Verbindung fehlgeschlagen; Zugangsdaten und Serverprotokoll prüfen.'];
    }
}

function send_campaign_message(array $profile, array $campaign, array $contact, string $unsubscribeUrl): array
{
    $mailer = create_mailer($profile);
    $fromEmail = (string) $campaign['from_email'];
    $mailer->setFrom($fromEmail, (string) $campaign['from_name']);
    if (!empty($campaign['reply_to'])) {
        $mailer->addReplyTo((string) $campaign['reply_to']);
    }
    $mailer->addAddress((string) $contact['email']);
    $mailer->Subject = (string) $campaign['subject'];
    $mailer->isHTML(true);
    $mailer->Body = personalize((string) $campaign['html_content'], $contact, $unsubscribeUrl);
    $mailer->AltBody = personalize((string) $campaign['text_content'], $contact, $unsubscribeUrl);
    $mailer->addCustomHeader('List-Unsubscribe', '<' . $unsubscribeUrl . '>');
    $mailer->addCustomHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');
    try {
        $mailer->send();
        $reply = (string) $mailer->getSMTPInstance()->getLastReply();
        preg_match('/\b([245]\d\d)\b/', $reply, $matches);
        $mailer->smtpClose();
        return ['accepted' => true, 'smtp_code' => isset($matches[1]) ? (int) $matches[1] : null, 'error' => null];
    } catch (\Throwable $exception) {
        // A broken connection during DATA may leave SMTP acceptance unknown; don't resend automatically.
        $reply = (string) $mailer->getSMTPInstance()->getLastReply();
        preg_match('/^\s*([45]\d\d)\b/', $reply, $matches);
        $code = isset($matches[1]) ? (int) $matches[1] : null;
        $mailer->smtpClose();
        return [
            'accepted' => false,
            'smtp_code' => $code,
            'temporary' => $code !== null && $code >= 400 && $code < 500,
            'error' => $code !== null
                ? 'SMTP-Server hat die Nachricht mit einem Fehlercode abgelehnt.'
                : 'SMTP-Sendung fehlgeschlagen oder Annahmestatus unbekannt; keine automatische Wiederholung.',
        ];
    }
}

function personalize(string $content, array $contact, string $unsubscribeUrl): string
{
    return strtr($content, [
        '{{email}}' => htmlspecialchars((string) $contact['email'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
        '{{firstname}}' => htmlspecialchars((string) ($contact['first_name'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
        '{{lastname}}' => htmlspecialchars((string) ($contact['last_name'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
        '{{unsubscribe_url}}' => htmlspecialchars($unsubscribeUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
    ]);
}

function unsubscribe_url(int $contactId, array $config): string
{
    $key = hash_hmac('sha256', 'unsubscribe-signing', encryption_key($config), true);
    $token = rtrim(strtr(base64_encode((string) $contactId), '+/', '-_'), '=');
    $signature = hash_hmac('sha256', $token, $key);
    $base = rtrim((string) ($config['app']['url'] ?? ''), '/');
    $scheme = parse_url($base, PHP_URL_SCHEME);
    $parts = parse_url($base);
    $allowHttp = ($config['app']['allow_insecure_http'] ?? false) === true;
    if ($base === '' || !filter_var($base, FILTER_VALIDATE_URL) ||
        !is_array($parts) || empty($parts['host']) || isset($parts['query']) || isset($parts['fragment']) ||
        !in_array($scheme, $allowHttp ? ['http', 'https'] : ['https'], true) ||
        isset($parts['user']) || isset($parts['pass'])) {
        throw new RuntimeException('app.url muss eine gültige HTTPS-Adresse sein (HTTP nur bei expliziter Entwicklungsfreigabe).');
    }
    return $base . '/unsubscribe.php?t=' . rawurlencode($token . '.' . $signature);
}

function verify_unsubscribe_token(string $value, array $config): ?int
{
    if (!preg_match('/^([A-Za-z0-9_-]+)\.([a-f0-9]{64})$/', $value, $matches)) {
        return null;
    }
    $expected = hash_hmac('sha256', $matches[1], hash_hmac('sha256', 'unsubscribe-signing', encryption_key($config), true));
    $decoded = base64_decode(strtr($matches[1], '-_', '+/') . str_repeat('=', (4 - strlen($matches[1]) % 4) % 4), true);
    if (!hash_equals($expected, $matches[2]) || $decoded === false || !ctype_digit($decoded)) {
        return null;
    }
    return (int) $decoded;
}
