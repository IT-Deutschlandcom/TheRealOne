<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/Mailer.php';

$config = [
    'app' => [
        'url' => 'https://mail.example.test',
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
], 'https://mail.example.test/unsubscribe?t=abc');
if (str_contains($personalized, '<User>') || !str_contains($personalized, '&lt;User&gt;') ||
    !str_contains($personalized, 'https://mail.example.test/unsubscribe')) {
    throw new RuntimeException('Personalization escaping test failed.');
}
echo "Mailer security smoke tests passed.\n";
