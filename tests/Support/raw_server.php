<?php

declare(strict_types=1);

// The raw server of the socket tests (LocalServer::rawUrl()): it writes whole answers itself, so
// the status line and the headers can arrive slowly too, the body can pause, and an answer can
// end before its framing says. The answer is written in pieces, `delay` seconds apart:
//
// - `/trickle-headers?delay=` writes it one byte at a time.
// - `/body-pause?delay=` writes the headers, pauses once, then writes the body.
// - `/pdf` answers a PDF whole; `/chunk-cut` sends it chunked and closes after its first chunk,
//   without the last one; `/gzip-cut` sends it compressed and closes after half of the bytes
//   its Content-Length declares.

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
    $head = "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: " . \strlen($body) . "\r\nConnection: close\r\n\r\n";
    // Bytes that do not compress, so half of their gzip still inflates into a part of them.
    $pdf = '%PDF-1.7 ' . implode('', array_map(static fn(int $index): string => hash('sha256', (string) $index, true), range(1, 32))) . ' %%EOF';
    $gzip = (string) gzencode($pdf);
    $pdfHead = static fn(string $headers): string => "HTTP/1.1 200 OK\r\nContent-Type: application/pdf\r\n{$headers}Connection: close\r\n\r\n";
    $pieces = match ((string) parse_url($target, \PHP_URL_PATH)) {
        '/trickle-headers' => str_split($head . $body),
        '/body-pause' => [$head, $body],
        '/pdf' => [$pdfHead('Content-Length: ' . \strlen($pdf) . "\r\n") . $pdf],
        '/chunk-cut' => [$pdfHead("Transfer-Encoding: chunked\r\n") . "14\r\n" . substr($pdf, 0, 20) . "\r\n"],
        '/gzip-cut' => [$pdfHead("Content-Encoding: gzip\r\nContent-Length: " . \strlen($gzip) . "\r\n") . substr($gzip, 0, intdiv(\strlen($gzip), 2))],
        default => ["HTTP/1.1 404 Not Found\r\nContent-Length: 0\r\nConnection: close\r\n\r\n"],
    };
    foreach ($pieces as $index => $piece) {
        if ($index > 0) {
            usleep($delay);
        }
        if (@fwrite($connection, $piece) === false) {
            break;
        }
        fflush($connection);
    }
    fclose($connection);
}
