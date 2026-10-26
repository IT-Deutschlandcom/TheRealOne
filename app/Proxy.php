<?php
declare(strict_types=1);

namespace MailMarketing;

use RuntimeException;

function validate_proxy(string $host, int $port, string $username, string $password, string $protocol = 'socks5'): void
{
    if (!in_array($protocol, ['socks5', 'http', 'https'], true)) {
        throw new RuntimeException('Unbekanntes Proxy-Protokoll. Erlaubt sind SOCKS5, HTTP und HTTPS.');
    }
    if (strlen($host) > 253 ||
        !(filter_var($host, FILTER_VALIDATE_IP) || filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) ||
        $port < 1 || $port > 65535 || strlen($username) > 254 || strlen($password) > 255 ||
        preg_match('/[\x00-\x1f\x7f]/', $username . $password) ||
        (($username === '') !== ($password === ''))) {
        throw new RuntimeException('Proxy-Adresse, Port oder Zugangsdaten sind ungültig (Benutzername maximal 254, Passwort maximal 255 Bytes; beide angeben oder beide leer lassen).');
    }
    if ($protocol !== 'socks5' && str_contains($username, ':')) {
        throw new RuntimeException('HTTP-/HTTPS-Proxy-Benutzernamen dürfen keinen Doppelpunkt enthalten.');
    }
}

function parse_proxy_line(string $line, string $protocol = 'socks5'): array
{
    $line = trim($line);
    $separator = strrpos($line, '@');
    if ($separator === false) {
        throw new RuntimeException('Erwartetes Format: user:password@ip:port.');
    }
    $credentials = explode(':', substr($line, 0, $separator), 2);
    $endpoint = substr($line, $separator + 1);
    if (count($credentials) !== 2 || $credentials[0] === '' || $credentials[1] === '' ||
        !preg_match('/^(\[[0-9a-fA-F:]+\]|[^:]+):([0-9]{1,5})$/D', $endpoint, $matches)) {
        throw new RuntimeException('Erwartetes Format: user:password@ip:port.');
    }
    $host = trim($matches[1], '[]');
    if (!filter_var($host, FILTER_VALIDATE_IP)) {
        throw new RuntimeException('Der Massenimport erwartet eine gültige IP-Adresse.');
    }
    $host = inet_ntop(inet_pton($host));
    $port = (int) $matches[2];
    validate_proxy($host, $port, $credentials[0], $credentials[1], $protocol);
    return ['host' => $host, 'port' => $port, 'username' => $credentials[0], 'password' => $credentials[1], 'protocol' => $protocol];
}
