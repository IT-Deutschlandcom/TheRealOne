<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/Mailer.php';

$config = [
    'app' => [
        'url' => 'http://mail.example.test/cms',
        'encryption_key' => str_repeat('a', 64),
        'allow_insecure_http' => true,
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
try {
    \MailMarketing\unsubscribe_url(42, $httpConfig);
    throw new RuntimeException('HTTP unsubscribe URL was allowed without development opt-in.');
} catch (RuntimeException $exception) {
    if ($exception->getMessage() === 'HTTP unsubscribe URL was allowed without development opt-in.') {
        throw $exception;
    }
}
echo "Mailer security smoke tests passed.\n";
