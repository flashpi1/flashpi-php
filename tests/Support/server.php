<?php

declare(strict_types=1);

// The router of the `php -S` server of the socket tests (LocalServer): answers that arrive at the
// speed a test asks for, with `?delay=` seconds between their pieces.

$uri = $_SERVER['REQUEST_URI'] ?? '/';
$path = (string) parse_url(\is_string($uri) ? $uri : '/', \PHP_URL_PATH);
$search = $_SERVER['QUERY_STRING'] ?? '';
parse_str(\is_string($search) ? $search : '', $query);
$delay = is_numeric($query['delay'] ?? null) ? (int) ((float) $query['delay'] * 1e6) : 0;

switch ($path) {
    case '/trickle':
        header('Content-Type: application/json');
        foreach (['{"status"', ':', '"operational"', '}'] as $piece) {
            usleep($delay);
            echo $piece;
            flush();
        }
        break;
    case '/slow-headers':
        usleep($delay);
        header('Content-Type: application/json');
        echo '{"status":"operational"}';
        break;
    case '/gzip':
        header('Content-Type: application/json');
        header('Content-Encoding: gzip');
        echo gzencode('{"status":"operational"}');
        break;
    case '/short':
        // An answer whose connection closes before the body its Content-Length declares.
        header('Content-Type: application/json');
        header('Content-Length: 500');
        echo '{"status":"oper';
        break;
    case '/redirect':
        header('Location: https://example.com/elsewhere', true, 302);
        break;
    case '/echo':
        header('Content-Type: application/json');
        echo json_encode([
            'method' => $_SERVER['REQUEST_METHOD'] ?? null,
            'authorization' => $_SERVER['HTTP_AUTHORIZATION'] ?? null,
            'idempotencyKey' => $_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? null,
            'body' => file_get_contents('php://input'),
        ]);
        break;
    default:
        http_response_code(404);
        header('Content-Type: application/problem+json');
        echo json_encode(['code' => 'not_found', 'title' => 'Not found', 'detail' => "No route {$path}"]);
}
