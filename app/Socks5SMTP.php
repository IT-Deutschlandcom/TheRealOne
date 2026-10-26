<?php
declare(strict_types=1);

namespace MailMarketing;

use PHPMailer\PHPMailer\SMTP;
use RuntimeException;

require_once __DIR__ . '/Proxy.php';
require_once dirname(__DIR__) . '/assets/vendor/phpmailer/SMTP.php';

class Socks5SMTP extends SMTP
{
    protected array $proxy;

    public function __construct(array $proxy)
    {
        validate_proxy($proxy['host'], (int) $proxy['port'], $proxy['username'], $proxy['password'], $proxy['protocol'] ?? 'socks5');
        $this->proxy = $proxy;
    }

    protected function getSMTPConnection($host, $port = null, $timeout = 30, $options = [])
    {
        $connection = false;
        $deadline = microtime(true) + $timeout;
        try {
            $implicitTls = str_starts_with($host, 'ssl://');
            $target = $implicitTls ? substr($host, 6) : $host;
            $target = trim($target, '[]');
            if ($port < 1 || $port > 65535 || strlen($target) > 253 ||
                !(filter_var($target, FILTER_VALIDATE_IP) || filter_var($target, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME))) {
                throw new RuntimeException('Ungültiges SMTP-Ziel für den Proxy.');
            }
            // TLS must authenticate the SMTP server, not the proxy endpoint.
            $options['ssl']['peer_name'] = $target;
            $options['ssl']['SNI_enabled'] = true;
            $options['ssl']['verify_peer'] = true;
            $options['ssl']['verify_peer_name'] = true;
            $options['ssl']['allow_self_signed'] = false;
            $connection = $this->openTunnel($target, (int) $port, $timeout, $options, $deadline);
            $this->setDeadline($connection, $deadline);
            if ($implicitTls && @stream_socket_enable_crypto($connection, true, STREAM_CRYPTO_METHOD_TLS_CLIENT) !== true) {
                throw new RuntimeException('TLS-Verifikation des SMTP-Servers fehlgeschlagen.');
            }
            stream_set_timeout($connection, $timeout);
            return $connection;
        } catch (RuntimeException $exception) {
            if (is_resource($connection)) {
                fclose($connection);
            }
            $this->close();
            $this->setError($exception->getMessage());
            error_log('Proxy SMTP transport: ' . $exception->getMessage());
            return false;
        }
    }

    protected function openTunnel(string $target, int $port, int $timeout, array $options, float $deadline)
    {
        $connection = false;
        try {
            $proxyHost = $this->proxy['host'];
            if (str_contains($proxyHost, ':')) {
                $proxyHost = '[' . $proxyHost . ']';
            }
            $connection = @stream_socket_client(
                'tcp://' . $proxyHost . ':' . $this->proxy['port'],
                $errno,
                $error,
                $timeout,
                STREAM_CLIENT_CONNECT,
                stream_context_create($options)
            );
            if (!is_resource($connection)) {
                throw new RuntimeException('SOCKS5-Proxy nicht erreichbar.');
            }
            $method = $this->proxy['username'] === '' ? "\x00" : "\x02";
            $this->writeBytes($connection, "\x05\x01" . $method, $deadline);
            if ($this->readBytes($connection, 2, $deadline) !== "\x05" . $method) {
                throw new RuntimeException('SOCKS5-Authentifizierungsmethode abgelehnt.');
            }
            if ($method === "\x02") {
                $username = $this->proxy['username'];
                $password = $this->proxy['password'];
                $this->writeBytes($connection, "\x01" . chr(strlen($username)) . $username . chr(strlen($password)) . $password, $deadline);
                if ($this->readBytes($connection, 2, $deadline) !== "\x01\x00") {
                    throw new RuntimeException('SOCKS5-Anmeldung fehlgeschlagen.');
                }
            }
            $packed = @inet_pton($target);
            $address = $packed === false
                ? "\x03" . chr(strlen($target)) . $target
                : (strlen($packed) === 4 ? "\x01" : "\x04") . $packed;
            $this->writeBytes($connection, "\x05\x01\x00" . $address . pack('n', $port), $deadline);
            $reply = $this->readBytes($connection, 4, $deadline);
            if (substr($reply, 0, 3) !== "\x05\x00\x00") {
                throw new RuntimeException('SOCKS5-Verbindung zum SMTP-Server abgelehnt.');
            }
            $addressLength = match (ord($reply[3])) {
                1 => 4,
                4 => 16,
                3 => ord($this->readBytes($connection, 1, $deadline)),
                default => throw new RuntimeException('Ungültige SOCKS5-Antwort.'),
            };
            if ($addressLength === 0) {
                throw new RuntimeException('Ungültige SOCKS5-Antwortadresse.');
            }
            $this->readBytes($connection, $addressLength + 2, $deadline);
            return $connection;
        } catch (RuntimeException $exception) {
            if (is_resource($connection)) {
                fclose($connection);
            }
            throw $exception;
        }
    }

    protected function setDeadline($connection, float $deadline): void
    {
        $remaining = $deadline - microtime(true);
        if ($remaining <= 0) {
            throw new RuntimeException('Zeitlimit beim Proxy-Verbindungsaufbau überschritten.');
        }
        $seconds = (int) $remaining;
        stream_set_timeout($connection, $seconds, max(1, (int) (($remaining - $seconds) * 1_000_000)));
    }

    protected function readBytes($connection, int $length, float $deadline): string
    {
        $data = '';
        while (strlen($data) < $length) {
            $this->setDeadline($connection, $deadline);
            $part = @fread($connection, $length - strlen($data));
            if ($part === false || $part === '') {
                throw new RuntimeException('Proxy-Antwort unvollständig oder Zeitlimit überschritten.');
            }
            $data .= $part;
        }
        return $data;
    }

    protected function writeBytes($connection, string $data, float $deadline): void
    {
        while ($data !== '') {
            $this->setDeadline($connection, $deadline);
            $written = @fwrite($connection, $data);
            if ($written === false || $written === 0) {
                throw new RuntimeException('Proxy-Anfrage konnte nicht vollständig übertragen werden.');
            }
            $data = substr($data, $written);
        }
    }
}
