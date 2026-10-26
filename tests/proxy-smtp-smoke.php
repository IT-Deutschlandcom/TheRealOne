<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/app/Mailer.php';

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function read_exact($socket, int $length): string
{
    $data = '';
    while (strlen($data) < $length) {
        $part = fread($socket, $length - strlen($data));
        check(is_string($part) && $part !== '', 'Fixture received incomplete data.');
        $data .= $part;
    }
    return $data;
}

function serve_smtp($socket, string $scenario): void
{
    $implicit = in_array($scenario, ['implicit-tls', 'wrong-name', 'untrusted'], true);
    $tls = false;
    if ($implicit) {
        $tls = @stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_SERVER);
    }
    if ($implicit && $tls !== true) {
        return;
    }
    fwrite($socket, "220 fixture ESMTP\r\n");
    $data = false;
    $bodyLines = 0;
    while (($line = fgets($socket)) !== false) {
        if ($data) {
            if ($line === ".\r\n") {
                check($bodyLines === 2000, 'SMTP DATA was truncated or corrupted.');
                $data = false;
                fwrite($socket, "250 2.0.0 accepted\r\n");
            } elseif ($line === "Fixture message payload for testing proxy backpressure.\r\n") {
                $bodyLines++;
            }
        } elseif (str_starts_with($line, 'EHLO')) {
            fwrite($socket, $scenario === 'starttls' && !$tls
                ? "250-fixture\r\n250 STARTTLS\r\n" : "250 fixture\r\n");
        } elseif (str_starts_with($line, 'STARTTLS')) {
            fwrite($socket, "220 Ready for TLS\r\n");
            $tls = @stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_SERVER);
            check($tls === true, 'STARTTLS fixture handshake failed.');
        } elseif (str_starts_with($line, 'DATA')) {
            fwrite($socket, "354 Send data\r\n");
            $data = true;
        } elseif (str_starts_with($line, 'QUIT')) {
            fwrite($socket, "221 Goodbye\r\n");
            break;
        } else {
            fwrite($socket, "250 OK\r\n");
        }
    }
}

function run_http_fixture($socket, string $scenario, string $certificate, bool $secure): void
{
    if ($secure && @stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_SERVER) !== true) {
        check(in_array($scenario, ['untrusted-proxy', 'wrong-proxy-name'], true), 'Unexpected proxy TLS failure.');
        return;
    }
    if (in_array($scenario, ['untrusted-proxy', 'wrong-proxy-name'], true)) {
        check(@fread($socket, 1) === '', 'CONNECT was sent despite an invalid proxy certificate.');
        return;
    }
    $headers = '';
    while (!str_ends_with($headers, "\r\n\r\n")) {
        $headers .= read_exact($socket, 1);
        check(strlen($headers) < 16384, 'Oversized CONNECT request.');
    }
    $host = match ($scenario) {
        'ipv4' => '192.0.2.1',
        'ipv6' => '[2001:db8::1]',
        'wrong-name' => 'other.example.test',
        default => 'smtp.example.test',
    };
    check(str_starts_with($headers, 'CONNECT ' . $host . ":2525 HTTP/1.1\r\n"), 'Wrong CONNECT target.');
    check(str_contains($headers, "\r\nHost: " . $host . ":2525\r\n"), 'Wrong CONNECT Host.');
    check(str_contains($headers, 'Proxy-Authorization: Basic ' . base64_encode('fixture-user:secret:@value'))
        === ($scenario !== 'no-auth'), 'Incorrect HTTP proxy authentication.');
    if ($scenario === 'timeout') {
        usleep(1_500_000);
        return;
    }
    $failure = match ($scenario) {
        'reject-auth' => "HTTP/1.1 407 Proxy Authentication Required\r\n\r\n",
        'reject-connect' => "HTTP/1.1 403 Forbidden\r\n\r\n",
        'truncated' => "HTTP/1.1 200 OK\r\n",
        'bad-response' => "NOT HTTP\r\n\r\n",
        'large-headers' => "HTTP/1.1 200 OK\r\nX-Long: " . str_repeat('a', 16400) . "\r\n\r\n",
        default => null,
    };
    if ($failure !== null) {
        @fwrite($socket, $failure);
        return;
    }
    $process = proc_open([PHP_BINARY, __FILE__, '--smtp-fixture', $scenario, $certificate],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    check(is_resource($process), 'Could not launch SMTP backend.');
    try {
        $endpoint = trim((string) fgets($pipes[1]));
        $backend = stream_socket_client('tcp://' . $endpoint, $errno, $error, 3);
        check(is_resource($backend), 'SMTP backend unavailable.');
        // Fragment headers; the greeting may immediately follow the final header bytes.
        fwrite($socket, "HTTP/1.1 200 Connection");
        usleep(1000);
        fwrite($socket, " established\r\n\r\n");
        $sockets = [$socket, $backend];
        while (true) {
            $read = $sockets;
            $write = $except = null;
            check(stream_select($read, $write, $except, 5) > 0, 'Fixture relay timed out.');
            foreach ($read as $source) {
                $bytes = fread($source, 16384);
                if ($bytes === false || ($bytes === '' && feof($source))) {
                    break 2;
                }
                if ($bytes !== '') {
                    $destination = $source === $socket ? $backend : $socket;
                    check(fwrite($destination, $bytes) === strlen($bytes), 'Fixture relay write failed.');
                }
            }
        }
        fclose($backend);
    } finally {
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        check(proc_close($process) === 0, 'SMTP backend failed: ' . $output . $errors);
    }
}

function run_fixture(string $scenario, string $certificate, string $protocol = 'socks5', ?string $proxyCertificate = null): void
{
    $context = stream_context_create(['ssl' => [
        'local_cert' => $protocol === 'https' ? $proxyCertificate : $certificate,
        'local_pk' => $protocol === 'https' ? $proxyCertificate : $certificate,
    ]]);
    $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);
    check(is_resource($server), 'Could not start local SOCKS5 fixture.');
    echo stream_socket_get_name($server, false) . "\n";
    flush();
    $socket = stream_socket_accept($server, 10);
    check(is_resource($socket), 'No fixture connection.');
    stream_set_timeout($socket, 5);
    if ($protocol !== 'socks5') {
        run_http_fixture($socket, $scenario, $certificate, $protocol === 'https');
        fclose($socket);
        fclose($server);
        return;
    }
    $method = $scenario === 'no-auth' ? "\x00" : "\x02";
    check(read_exact($socket, 3) === "\x05\x01" . $method, 'Incorrect SOCKS5 methods.');
    if ($scenario === 'bad-method') {
        fwrite($socket, "\x05\x00");
    } elseif ($scenario === 'truncated') {
        fwrite($socket, "\x05");
    } elseif ($scenario === 'timeout') {
        usleep(1_500_000);
    } else {
        // Fragment the greeting to exercise exact-length reads.
        fwrite($socket, "\x05");
        usleep(1_000);
        fwrite($socket, $method);
        if ($method === "\x02") {
            check(read_exact($socket, 1) === "\x01", 'Incorrect auth version.');
            $username = read_exact($socket, ord(read_exact($socket, 1)));
            $password = read_exact($socket, ord(read_exact($socket, 1)));
            check($username === 'fixture-user' && $password === 'secret:@value', 'Incorrect proxy credentials.');
            fwrite($socket, $scenario === 'reject-auth' ? "\x01\x01" : "\x01\x00");
        }
        if ($scenario !== 'reject-auth') {
            check(read_exact($socket, 3) === "\x05\x01\x00", 'Incorrect SOCKS5 CONNECT.');
            $type = ord(read_exact($socket, 1));
            $length = match ($type) {
                1 => 4,
                4 => 16,
                3 => ord(read_exact($socket, 1)),
                default => throw new RuntimeException('Invalid target type.'),
            };
            $address = read_exact($socket, $length);
            $port = unpack('nport', read_exact($socket, 2))['port'];
            $expectedHost = match ($scenario) {
                'ipv4' => '192.0.2.1',
                'ipv6' => '2001:db8::1',
                'wrong-name' => 'other.example.test',
                default => 'smtp.example.test',
            };
            check(($type === 3 ? $address : inet_ntop($address)) === $expectedHost, 'Incorrect SMTP destination (DNS must be resolved by the proxy).');
            check($port === 2525, 'Incorrect SMTP destination port.');
            if ($scenario === 'reject-connect') {
                fwrite($socket, "\x05\x05\x00\x01\x00\x00\x00\x00\x00\x00");
            } elseif ($scenario === 'bad-address') {
                fwrite($socket, "\x05\x00\x00\x09");
            } else {
                $reply = match ($scenario) {
                    'ipv4' => "\x05\x00\x00\x04" . str_repeat("\x00", 16),
                    'ipv6' => "\x05\x00\x00\x03\x04bind",
                    default => "\x05\x00\x00\x01\x7f\x00\x00\x01",
                };
                fwrite($socket, $reply . pack('n', 1080));
                serve_smtp($socket, $scenario);
            }
        }
    }
    fclose($socket);
    fclose($server);
}

if (($argv[1] ?? '') === '--fixture') {
    run_fixture($argv[2], $argv[3], $argv[4] ?? 'socks5', $argv[5] ?? null);
    exit;
}
if (($argv[1] ?? '') === '--smtp-fixture') {
    $context = stream_context_create(['ssl' => ['local_cert' => $argv[3], 'local_pk' => $argv[3]]]);
    $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);
    check(is_resource($server), 'Could not start SMTP backend.');
    echo stream_socket_get_name($server, false) . "\n";
    flush();
    $socket = stream_socket_accept($server, 5);
    check(is_resource($socket), 'No SMTP backend connection.');
    stream_set_timeout($socket, 5);
    serve_smtp($socket, $argv[2]);
    fclose($socket);
    fclose($server);
    exit;
}

$config = ['app' => [
    'encryption_key' => str_repeat('a', 64),
    'url' => 'http://panel.example.test/cms',
]];
$proxy = \MailMarketing\parse_proxy_line('fixture-user:secret:@value@192.0.2.1:1080');
check($proxy['password'] === 'secret:@value', 'Password delimiters were corrupted.');
$ipv6 = \MailMarketing\parse_proxy_line('u:p@[2001:0db8::1]:65535');
check($ipv6['host'] === '2001:db8::1' && $ipv6['port'] === 65535, 'IPv6 parsing failed.');
foreach (['socks5', 'http', 'https'] as $protocol) {
    check(\MailMarketing\parse_proxy_line('u:p@192.0.2.1:8080', $protocol)['protocol'] === $protocol, 'Import lost protocol.');
}
$rejected = false;
try {
    \MailMarketing\parse_proxy_line('u:p@192.0.2.1:8080', 'ftp');
} catch (RuntimeException $exception) {
    $rejected = true;
}
check($rejected, 'Unsupported proxy protocol was accepted.');
foreach ([
    '', 'u:p@example.test:1080', 'u:p@192.0.2.1:0', 'u:p@192.0.2.1:65536',
    'u:p@192.0.2.1:-1', ':p@192.0.2.1:1080', 'u:@192.0.2.1:1080',
    "u:p\nx@192.0.2.1:1080", 'u:p@2001:db8::1:1080',
    str_repeat('u', 255) . ':p@192.0.2.1:1080',
    'u:' . str_repeat('p', 256) . '@192.0.2.1:1080',
] as $invalid) {
    $rejected = false;
    try {
        \MailMarketing\parse_proxy_line($invalid);
    } catch (RuntimeException $exception) {
        $rejected = true;
    }
    check($rejected, 'Invalid proxy input was accepted.');
}
\MailMarketing\validate_proxy('127.0.0.1', 1080, '', '');

$pdo = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$pdo->exec('CREATE TABLE smtp_profiles (id INTEGER, is_active INTEGER, proxy_id INTEGER, password_encrypted TEXT)');
$pdo->exec("CREATE TABLE proxies (id INTEGER, host TEXT, port INTEGER, username TEXT, password_encrypted TEXT, is_active INTEGER, protocol TEXT DEFAULT 'socks5')");
$pdo->exec('INSERT INTO smtp_profiles VALUES (1,1,NULL,NULL)');
check(\MailMarketing\active_smtp_profile($pdo, $config)['proxy'] === null, 'Direct profile changed.');
$insert = $pdo->prepare('INSERT INTO proxies (id, host, port, username, password_encrypted, is_active) VALUES (1, :host, :port, :username, :password, 1)');
$insert->execute([
    'host' => $proxy['host'], 'port' => $proxy['port'], 'username' => $proxy['username'],
    'password' => \MailMarketing\encrypt_secret($proxy['password'], $config),
]);
$pdo->exec('UPDATE smtp_profiles SET proxy_id=1');
check(\MailMarketing\active_smtp_profile($pdo, $config)['proxy']['password'] === 'secret:@value', 'Assigned secret was not decrypted.');
foreach (['http', 'https'] as $protocol) {
    $pdo->exec("UPDATE proxies SET protocol='$protocol'");
    check(\MailMarketing\active_smtp_profile($pdo, $config)['proxy']['protocol'] === $protocol, 'Assigned protocol was not loaded.');
}
$pdo->exec('UPDATE proxies SET is_active=0');
$rejected = false;
try {
    \MailMarketing\active_smtp_profile($pdo, $config);
} catch (RuntimeException $exception) {
    $rejected = true;
}
check($rejected, 'Inactive proxy silently fell back to direct transport.');
$pdo->exec('DELETE FROM proxies');
$rejected = false;
try {
    \MailMarketing\active_smtp_profile($pdo, $config);
} catch (RuntimeException $exception) {
    $rejected = true;
}
check($rejected, 'Missing proxy silently fell back to direct transport.');
$rejected = false;
try {
    \MailMarketing\create_mailer(['proxy_id' => 1]);
} catch (RuntimeException $exception) {
    $rejected = true;
}
check($rejected, 'Incomplete proxy profile silently fell back to direct transport.');

$key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
$csr = openssl_csr_new(['commonName' => 'smtp.example.test'], $key, ['digest_alg' => 'sha256']);
$certificate = openssl_csr_sign($csr, null, $key, 1, ['digest_alg' => 'sha256']);
openssl_x509_export($certificate, $certificatePem);
openssl_pkey_export($key, $keyPem);
$certificatePath = tempnam(sys_get_temp_dir(), 'proxy-smtp-');
file_put_contents($certificatePath, $certificatePem . $keyPem);
$proxyCsr = openssl_csr_new(['commonName' => 'localhost'], $key, ['digest_alg' => 'sha256']);
$proxyCertificate = openssl_csr_sign($proxyCsr, null, $key, 1, ['digest_alg' => 'sha256']);
openssl_x509_export($proxyCertificate, $proxyCertificatePem);
$proxyCertificatePath = tempnam(sys_get_temp_dir(), 'proxy-tls-');
file_put_contents($proxyCertificatePath, $proxyCertificatePem . $keyPem);
$caPath = tempnam(sys_get_temp_dir(), 'proxy-ca-');
file_put_contents($caPath, $certificatePem . $proxyCertificatePem);

try {
    foreach (['socks5', 'http', 'https'] as $protocol) {
        $scenarios = $protocol === 'socks5' ? [
            'auth', 'no-auth', 'ipv4', 'ipv6', 'implicit-tls', 'starttls',
            'wrong-name', 'untrusted', 'bad-method', 'reject-auth', 'reject-connect',
            'bad-address', 'truncated', 'timeout',
        ] : [
            'auth', 'no-auth', 'ipv4', 'ipv6', 'implicit-tls', 'starttls', 'wrong-name', 'untrusted',
            'reject-auth', 'reject-connect', 'truncated', 'timeout', 'bad-response', 'large-headers',
        ];
        if ($protocol === 'https') {
            $scenarios = array_merge($scenarios, ['untrusted-proxy', 'wrong-proxy-name']);
        }
        foreach ($scenarios as $scenario) {
            $process = proc_open([PHP_BINARY, __FILE__, '--fixture', $scenario, $certificatePath, $protocol, $proxyCertificatePath],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            check(is_resource($process), 'Could not launch local fixture.');
            try {
                $endpoint = trim((string) fgets($pipes[1]));
                check(preg_match('/^127\.0\.0\.1:(\d+)$/', $endpoint, $matches) === 1, 'Fixture did not become responsive.');
                $host = match ($scenario) {
                    'ipv4' => '192.0.2.1',
                    'ipv6' => '[2001:db8::1]',
                    'wrong-name' => 'other.example.test',
                    default => 'smtp.example.test',
                };
                $profile = [
                    'host' => $host, 'port' => 2525, 'username' => '', 'password' => '',
                    'timeout_seconds' => $scenario === 'timeout' ? 1 : 3,
                    'encryption' => match ($scenario) {
                        'implicit-tls', 'wrong-name', 'untrusted' => 'ssl',
                        'starttls' => 'tls',
                        default => 'none',
                    },
                    'proxy_id' => 1,
                    'proxy' => [
                        'host' => $protocol === 'https' && $scenario !== 'wrong-proxy-name' ? 'localhost' : '127.0.0.1',
                        'port' => (int) $matches[1], 'protocol' => $protocol,
                        'username' => $scenario === 'no-auth' ? '' : 'fixture-user',
                        'password' => $scenario === 'no-auth' ? '' : 'secret:@value',
                    ],
                ];
                if ($protocol === 'http' && $scenario === 'reject-auth') {
                    $result = \MailMarketing\test_smtp_connection($profile);
                    check(!$result['ok'] && str_contains($result['message'], '407'), 'Connection test hid the proxy error.');
                    continue;
                }
                $mailer = \MailMarketing\create_mailer($profile);
                check($protocol === 'socks5'
                    ? get_class($mailer->getSMTPInstance()) === \MailMarketing\Socks5SMTP::class
                    : $mailer->getSMTPInstance() instanceof \MailMarketing\HttpProxySMTP, 'Wrong proxy transport installed.');
                $mailer->SMTPOptions['ssl']['cafile'] = match ($scenario) {
                    'untrusted' => $proxyCertificatePath,
                    'untrusted-proxy' => $certificatePath,
                    default => $caPath,
                };
                $success = false;
                try {
                    $mailer->setFrom('sender@example.test', 'Fixture');
                    $mailer->addAddress('recipient@example.test');
                    $mailer->Subject = 'Local transport test';
                    $mailer->Body = str_repeat("Fixture message payload for testing proxy backpressure.\r\n", 2000) .
                        'Unsubscribe: ' . \MailMarketing\unsubscribe_url(42, $config);
                    $success = $mailer->send();
                } catch (\PHPMailer\PHPMailer\Exception $exception) {
                    $success = false;
                } finally {
                    $mailer->smtpClose();
                }
                check($success === in_array($scenario, ['auth', 'no-auth', 'ipv4', 'ipv6', 'implicit-tls', 'starttls'], true),
                    'Unexpected send result for ' . $protocol . '/' . $scenario);
            } finally {
                fclose($pipes[0]);
                $output = stream_get_contents($pipes[1]);
                $errors = stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                $status = proc_close($process);
                check($status === 0, 'Fixture failed: ' . $output . $errors);
            }
        }
    }
} finally {
    unlink($certificatePath);
    unlink($proxyCertificatePath);
    unlink($caPath);
}

echo "Proxy import and local SOCKS5/HTTP/HTTPS SMTP smoke tests passed.\n";
