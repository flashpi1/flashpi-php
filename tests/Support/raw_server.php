<?php

declare(strict_types=1);

// The raw server of the socket tests (LocalServer::rawUrl()): it writes whole answers itself, so
// the status line and the headers can arrive slowly too. `/trickle-headers?delay=` writes the
// answer one byte at a time, `delay` seconds apart.

$port = (int) ($argv[1] ?? 0);
$server = stream_socket_server("tcp://127.0.0.1:{$port}");
if ($server === false) {
    exit(1);
}
for (;;) {
    $connection = @stream_socket_accept($server, -1);
    if ($connection === false) {
        continue;
    }
    $request = '';
    while (!str_contains($request, "\r\n\r\n")) {
        $chunk = fread($connection, 8192);
        if ($chunk === false || $chunk === '') {
            break;
        }
        $request .= $chunk;
    }
    $target = explode(' ', $request)[1] ?? '/';
    parse_str((string) parse_url($target, \PHP_URL_QUERY), $query);
    $delay = is_numeric($query['delay'] ?? null) ? (int) ((float) $query['delay'] * 1e6) : 0;
    $body = '{"status":"operational"}';
    $answer = match ((string) parse_url($target, \PHP_URL_PATH)) {
        '/trickle-headers' => "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: " . \strlen($body) . "\r\nConnection: close\r\n\r\n{$body}",
        default => "HTTP/1.1 404 Not Found\r\nContent-Length: 0\r\nConnection: close\r\n\r\n",
    };
    foreach (str_split($answer) as $byte) {
        if (@fwrite($connection, $byte) === false) {
            break;
        }
        fflush($connection);
        usleep($delay);
    }
    fclose($connection);
}
