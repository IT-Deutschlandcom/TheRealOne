<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/Mailer.php';

$config = [
    'app' => [
        'url' => 'http://mail.example.test/cms',
        'encryption_key' => str_repeat('a', 64),
    ],
];
$encrypted = \MailMarketing\encrypt_secret('test-secret', $config);
if (\MailMarketing\decrypt_secret($encrypted, $config) !== 'test-secret') {
    throw new RuntimeException('SMTP secret encryption round-trip failed.');
}
$tampered = $encrypted;
$tampered[0] = $tampered[0] === 'A' ? 'B' : 'A';
try {
    \MailMarketing\decrypt_secret($tampered, $config);
    throw new RuntimeException('Tampered SMTP secret was not rejected.');
} catch (RuntimeException $exception) {
    if ($exception->getMessage() === 'Tampered SMTP secret was not rejected.') {
        throw $exception;
    }
}
$url = \MailMarketing\unsubscribe_url(42, $config);
parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
if (\MailMarketing\verify_unsubscribe_token($query['t'] ?? '', $config) !== 42 ||
    \MailMarketing\verify_unsubscribe_token('42.invalid', $config) !== null) {
    throw new RuntimeException('Signed unsubscribe token test failed.');
}
$personalized = \MailMarketing\personalize('{{firstname}} {{email}} {{unsubscribe_url}}', [
    'email' => 'person@example.test',
    'first_name' => '<User>',
    'last_name' => '',
], $url);
if (str_contains($personalized, '<User>') || !str_contains($personalized, '&lt;User&gt;') ||
    !str_contains($personalized, 'http://mail.example.test/cms/unsubscribe')) {
    throw new RuntimeException('Personalization escaping test failed.');
}
$httpConfig = $config;
$httpConfig['app']['allow_insecure_http'] = false;
if (\MailMarketing\unsubscribe_url(42, $httpConfig) !== $url) {
    throw new RuntimeException('Legacy HTTP configuration must not block unsubscribe URLs.');
}
$httpsConfig = $config;
$httpsConfig['app']['url'] = 'https://mail.example.test/cms/';
if (\MailMarketing\unsubscribe_url(42, $httpsConfig) !== preg_replace('/^http:/', 'https:', $url)) {
    throw new RuntimeException('HTTPS unsubscribe URL regression.');
}
foreach ([
    '', 'ftp://mail.example.test', 'http://user@mail.example.test',
    'http://mail.example.test?query=1', 'https://mail.example.test#fragment',
] as $invalidUrl) {
    $invalidConfig = $config;
    $invalidConfig['app']['url'] = $invalidUrl;
    try {
        \MailMarketing\unsubscribe_url(42, $invalidConfig);
    } catch (RuntimeException $exception) {
        continue;
    }
    throw new RuntimeException('Invalid unsubscribe base URL was not rejected.');
}
foreach ([
    'tls' => \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS,
    'ssl' => \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS,
] as $encryption => $expected) {
    $mailer = \MailMarketing\create_mailer([
        'host' => 'smtp.example.test', 'port' => $encryption === 'tls' ? 587 : 465,
        'username' => 'login@example.test', 'password' => 'test-secret',
        'timeout_seconds' => 20, 'encryption' => $encryption,
        'from_email' => 'profile@example.test', 'from_name' => 'Profile Sender',
    ]);
    if ($mailer->SMTPSecure !== $expected || !$mailer->SMTPAuth ||
        $mailer->SMTPOptions['ssl'] !== [
            'verify_peer' => true, 'verify_peer_name' => true, 'allow_self_signed' => false,
        ]) {
        throw new RuntimeException('SMTP encryption and certificate verification must remain enabled.');
    }
    $mailer->setFrom('campaign@example.test', 'Independent Display Name');
    $mailer->addAddress('recipient@example.test');
    $mailer->Subject = 'Independent sender test';
    $mailer->Body = 'Unsubscribe: ' . $url;
    if (!$mailer->preSend()) {
        throw new RuntimeException('Message preparation failed with an independent sender and HTTP URL.');
    }
    $mime = $mailer->getSentMIMEMessage();
    if (!str_contains($mime, 'From: Independent Display Name <campaign@example.test>') ||
        $mailer->Username !== 'login@example.test') {
        throw new RuntimeException('Campaign sender must remain independent of SMTP credentials.');
    }
}
echo "Mailer security smoke tests passed.\n";
