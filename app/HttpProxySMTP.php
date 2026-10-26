<?php
declare(strict_types=1);

namespace MailMarketing;

use RuntimeException;

require_once __DIR__ . '/Socks5SMTP.php';

final class HttpProxySMTP extends Socks5SMTP
{
    private $relay = null;
    private array $relayPipes = [];

    protected function openTunnel(string $target, int $port, int $timeout, array $options, float $deadline)
    {
        if ($this->proxy['protocol'] === 'http') {
            return $this->connectHttpTunnel($target, $port, $timeout, $options, $deadline);
        }
        $this->closeRelay();
        $binary = PHP_BINDIR . DIRECTORY_SEPARATOR . (PHP_OS_FAMILY === 'Windows' ? 'php.exe' : 'php');
        if (!is_callable('proc_open') || !is_file($binary)) {
            throw new RuntimeException('HTTPS-Proxys benötigen proc_open und PHP-CLI im PHP-Installationsverzeichnis.');
        }
        $connection = false;
        try {
            $this->relay = proc_open([$binary, __DIR__ . '/https-proxy-relay.php'],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', 'php://stderr', 'a']], $this->relayPipes);
            if (!is_resource($this->relay)) {
                throw new RuntimeException('HTTPS-Proxy-Relay konnte nicht gestartet werden.');
            }
            $token = bin2hex(random_bytes(32));
            $this->writeBytes($this->relayPipes[0], json_encode([
                'proxy' => $this->proxy, 'target' => $target, 'port' => $port,
                'timeout' => $timeout, 'options' => $options, 'deadline' => $deadline, 'token' => $token,
            ], JSON_THROW_ON_ERROR) . "\n", $deadline);
            fclose($this->relayPipes[0]);
            unset($this->relayPipes[0]);
            $this->setDeadline($this->relayPipes[1], $deadline);
            $status = fgets($this->relayPipes[1], 1024);
            if (!is_string($status) || !preg_match('/^READY (127\.0\.0\.1:[0-9]+)\n$/D', $status, $matches)) {
                throw new RuntimeException(is_string($status) && str_starts_with($status, 'ERROR ')
                    ? trim(substr($status, 6)) : 'HTTPS-Proxy-Relay meldet keine Verbindung.');
            }
            $connection = @stream_socket_client('tcp://' . $matches[1], $errno, $error,
                max(0.001, $deadline - microtime(true)), STREAM_CLIENT_CONNECT, stream_context_create($options));
            if (!is_resource($connection)) {
                throw new RuntimeException('Lokale Verbindung zum HTTPS-Proxy-Relay fehlgeschlagen.');
            }
            $this->writeBytes($connection, $token, $deadline);
            $this->setDeadline($this->relayPipes[1], $deadline);
            if (fgets($this->relayPipes[1], 1024) !== "OK\n") {
                throw new RuntimeException('HTTPS-Proxy-Relay konnte nicht authentifiziert werden.');
            }
            return $connection;
        } catch (\Throwable $exception) {
            if (is_resource($connection)) {
                fclose($connection);
            }
            $this->closeRelay();
            throw new RuntimeException($exception->getMessage(), 0, $exception);
        }
    }

    public function connectHttpTunnel(string $target, int $port, int $timeout, array $options, float $deadline)
    {
        $connection = false;
        try {
            $secure = $this->proxy['protocol'] === 'https';
            $host = $this->proxy['host'];
            if ($secure) {
                $options['ssl']['peer_name'] = $host;
                $options['ssl']['SNI_enabled'] = true;
                $options['ssl']['verify_peer'] = true;
                $options['ssl']['verify_peer_name'] = true;
                $options['ssl']['allow_self_signed'] = false;
            }
            $endpoint = str_contains($host, ':') ? '[' . $host . ']' : $host;
            $connection = @stream_socket_client(
                ($secure ? 'tls://' : 'tcp://') . $endpoint . ':' . $this->proxy['port'],
                $errno, $error, max(0.001, min($timeout, $deadline - microtime(true))),
                STREAM_CLIENT_CONNECT, stream_context_create($options)
            );
            if (!is_resource($connection)) {
                throw new RuntimeException($secure
                    ? 'HTTPS-Proxy nicht erreichbar oder TLS-Zertifikat des Proxys ungültig.'
                    : 'HTTP-Proxy nicht erreichbar.');
            }
            $authority = (str_contains($target, ':') ? '[' . $target . ']' : $target) . ':' . $port;
            $request = 'CONNECT ' . $authority . " HTTP/1.1\r\nHost: " . $authority . "\r\n";
            if ($this->proxy['username'] !== '') {
                $request .= 'Proxy-Authorization: Basic ' .
                    base64_encode($this->proxy['username'] . ':' . $this->proxy['password']) . "\r\n";
            }
            $this->writeBytes($connection, $request . "\r\n", $deadline);
            // Read exactly through the headers, leaving the SMTP greeting in the stream.
            $headers = '';
            while (!str_ends_with($headers, "\r\n\r\n")) {
                if (strlen($headers) >= 16384) {
                    throw new RuntimeException('HTTP-Proxy-Antwort überschreitet das Header-Limit.');
                }
                $headers .= $this->readBytes($connection, 1, $deadline);
            }
            if (!preg_match('/^HTTP\/1\.[01] ([0-9]{3})(?:[ \r])/', $headers, $matches)) {
                throw new RuntimeException('Ungültige HTTP-CONNECT-Antwort.');
            }
            $code = (int) $matches[1];
            if ($code < 200 || $code > 299) {
                throw new RuntimeException($code === 407
                    ? 'HTTP-Proxy-Anmeldung abgelehnt (407); Benutzername, Passwort und Basic-Authentifizierung prüfen.'
                    : 'HTTP-Proxy hat CONNECT zum SMTP-Port abgelehnt (Status ' . $code . ').');
            }
            stream_set_timeout($connection, $timeout);
            return $connection;
        } catch (RuntimeException $exception) {
            if (is_resource($connection)) {
                fclose($connection);
            }
            throw $exception;
        }
    }

    public function close()
    {
        parent::close();
        $this->closeRelay();
    }

    private function closeRelay(): void
    {
        foreach ($this->relayPipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        $this->relayPipes = [];
        if (is_resource($this->relay)) {
            if (proc_get_status($this->relay)['running']) {
                proc_terminate($this->relay);
            }
            proc_close($this->relay);
        }
        $this->relay = null;
    }

    public function __destruct()
    {
        $this->close();
    }
}
