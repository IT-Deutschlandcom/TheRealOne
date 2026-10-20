<?php
declare(strict_types=1);

return [
    'app' => [
        'name' => 'Mail Marketing CMS',
        'url' => 'https://example.com',
        'timezone' => 'Europe/Berlin',
        'debug' => false,
        'session_timeout' => 1800,
        'encryption_key' => 'REPLACE_WITH_64_RANDOM_HEX_CHARACTERS',
    ],
    'database' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'name' => 'marketing_cms',
        'username' => 'root',
        'password' => '',
    ],
    'mailer' => [
        'max_batch_size' => 25,
        'max_attempts' => 3,
    ],
];
