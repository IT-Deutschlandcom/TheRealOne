<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/HttpProxySMTP.php';

$status = false;
try {
    $settings = json_decode((string) fgets(STDIN), true, 512, JSON_THROW_ON_ERROR);
    $status = @stream_socket_client('tcp://' . $settings['status'], $errno, $error,
        max(0.001, $settings['deadline'] - microtime(true)), STREAM_CLIENT_CONNECT);
    if (!is_resource($status)) {
        throw new RuntimeException('HTTPS-Proxy-Relay konnte den Statuskanal nicht öffnen.');
    }
    if (fwrite($status, $settings['token']) !== strlen($settings['token'])) {
        throw new RuntimeException('HTTPS-Proxy-Relay konnte den Statuskanal nicht authentifizieren.');
    }
    $transport = new \MailMarketing\HttpProxySMTP($settings['proxy']);
    $remote = $transport->connectHttpTunnel($settings['target'], $settings['port'],
        $settings['timeout'], $settings['options'], $settings['deadline']);
    $server = @stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    if (!is_resource($server)) {
        throw new RuntimeException('HTTPS-Proxy-Relay konnte keinen lokalen Port öffnen.');
    }
    $ready = 'READY ' . stream_socket_get_name($server, false) . "\n";
    if (fwrite($status, $ready) !== strlen($ready)) {
        throw new RuntimeException('HTTPS-Proxy-Relay konnte den Status nicht senden.');
    }
    $local = @stream_socket_accept($server, max(0.001, $settings['deadline'] - microtime(true)));
    fclose($server);
    if (!is_resource($local)) {
        throw new RuntimeException('HTTPS-Proxy-Relay: lokale Verbindung ausgeblieben.');
    }
    stream_set_timeout($local, $settings['timeout']);
    $token = '';
    while (strlen($token) < 64) {
        $part = fread($local, 64 - strlen($token));
        if (!is_string($part) || $part === '') {
            throw new RuntimeException('HTTPS-Proxy-Relay: lokale Anmeldung unvollständig.');
        }
        $token .= $part;
    }
    if (!hash_equals($settings['token'], $token)) {
        throw new RuntimeException('HTTPS-Proxy-Relay: lokale Anmeldung abgelehnt.');
    }
    if (fwrite($status, "OK\n") !== 3) {
        throw new RuntimeException('HTTPS-Proxy-Relay konnte den Status nicht senden.');
    }
    fclose($status);
    $status = false;

    // Separate TLS streams preserve end-to-end SMTP TLS inside the proxy's TLS tunnel.
    stream_set_blocking($local, false);
    stream_set_blocking($remote, false);
    $sockets = [$local, $remote];
    $buffers = ['', ''];
    $ended = [false, false];
    $lastActivity = microtime(true);
    while (true) {
        $read = $write = [];
        foreach ($sockets as $index => $socket) {
            if (!$ended[$index] && strlen($buffers[1 - $index]) < 65536) {
                $read[] = $socket;
            }
            if ($buffers[$index] !== '') {
                $write[] = $socket;
            }
        }
        if (($ended[0] && $buffers[1] === '') || ($ended[1] && $buffers[0] === '')) {
            break;
        }
        $except = null;
        $ready = stream_select($read, $write, $except, 1);
        if ($ready === false) {
            throw new RuntimeException('HTTPS-Proxy-Relay: Netzwerküberwachung fehlgeschlagen.');
        }
        foreach ($read as $socket) {
            $index = $socket === $local ? 0 : 1;
            $data = fread($socket, 16384);
            if ($data === false) {
                throw new RuntimeException('HTTPS-Proxy-Relay: Lesen fehlgeschlagen.');
            }
            if ($data !== '') {
                $buffers[1 - $index] .= $data;
                $lastActivity = microtime(true);
            }
            $ended[$index] = feof($socket);
        }
        foreach ($write as $socket) {
            $index = $socket === $local ? 0 : 1;
            $written = fwrite($socket, $buffers[$index]);
            if ($written === false) {
                throw new RuntimeException('HTTPS-Proxy-Relay: Schreiben fehlgeschlagen.');
            }
            if ($written > 0) {
                $buffers[$index] = substr($buffers[$index], $written);
                $lastActivity = microtime(true);
            }
        }
        if (microtime(true) - $lastActivity > $settings['timeout']) {
            throw new RuntimeException('HTTPS-Proxy-Relay: Zeitlimit überschritten.');
        }
    }
    fclose($local);
    fclose($remote);
} catch (Throwable $exception) {
    error_log('HTTPS proxy relay: ' . $exception->getMessage());
    if (is_resource($status)) {
        @fwrite($status, 'ERROR ' . str_replace(["\r", "\n"], ' ', $exception->getMessage()) . "\n");
        fclose($status);
    }
    exit(1);
}
