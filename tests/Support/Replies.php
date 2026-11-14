<?php

declare(strict_types=1);

namespace Flashpi\Tests\Support;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;

/**
 * Answers for the mocked API.
 */
final class Replies
{
    public const TEST_KEY = 'fp_test_aaaaaaaaaaaaaaaabbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    public const LIVE_KEY = 'fp_live_aaaaaaaaaaaaaaaabbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    public const ID = '019a1b2c-3d4e-7f60-8a1b-2c3d4e5f6a7b';

    public const OTHER_ID = '019a1b2c-3d4e-7f60-8a1b-2c3d4e5f6a7c';

    private function __construct() {}

    /**
     * A JSON answer.
     *
     * @param array<string, string> $headers
     */
    public static function json(mixed $body, int $status = 200, array $headers = []): Response
    {
        return new Response(
            $status,
            [...['Content-Type' => 'application/json', 'X-Request-Id' => 'req_123'], ...$headers],
            json_encode($body, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE),
        );
    }

    /**
     * A Problem Details answer like the API's.
     *
     * @param array<string, mixed> $extra
     * @param array<string, string> $headers
     */
    public static function problem(int $status, string $code, array $extra = [], array $headers = []): Response
    {
        $body = [
            'type' => "https://flashpi.com.ec/docs/en/errors/{$code}",
            'title' => 'Problem',
            'status' => $status,
            'detail' => "Detail of {$code}",
            'instance' => '/v1/test',
            'code' => $code,
            'errors' => [],
            'requestId' => 'req_problem',
            ...$extra,
        ];

        return new Response(
            $status,
            [...['Content-Type' => 'application/problem+json; charset=utf-8', 'X-Request-Id' => 'req_problem'], ...$headers],
            json_encode($body, \JSON_THROW_ON_ERROR),
        );
    }

    /**
     * A list answer.
     *
     * @param list<array<string, mixed>> $data
     */
    public static function page(array $data, ?string $nextCursor = null): Response
    {
        return self::json(['data' => $data, 'pagination' => ['nextCursor' => $nextCursor, 'hasMore' => $nextCursor !== null]]);
    }

    /**
     * A minimal document as the API returns it (only the members the tests read).
     *
     * @return array<string, mixed>
     */
    public static function document(string $id = self::ID, string $status = 'processing'): array
    {
        return ['id' => $id, 'type' => 'invoice', 'status' => $status, 'environment' => 'test', 'accessKey' => str_repeat('1', 49)];
    }

    /**
     * A network failure, as Guzzle's handlers report one.
     *
     * @return \Closure(RequestInterface): never
     */
    public static function networkError(string $message = 'cURL error 7: Failed to connect'): \Closure
    {
        return static function (RequestInterface $request) use ($message): never {
            throw new ConnectException($message, $request);
        };
    }

    /**
     * A reply that takes `$seconds` and then times out, like a request the API never answers.
     *
     * @return \Closure(RequestInterface): never
     */
    public static function timeoutAfter(FakeClock $clock, float $seconds): \Closure
    {
        return static function (RequestInterface $request) use ($clock, $seconds): never {
            $clock->advance($seconds);

            throw new ConnectException('cURL error 28: Operation timed out after ' . (int) ($seconds * 1000) . ' milliseconds', $request);
        };
    }
}
