<?php

declare(strict_types=1);

namespace Flashpi\Tests\Support;

use PHPUnit\Framework\TestCase as PHPUnitTestCase;

/**
 * Helpers shared by the tests of the SDK.
 *
 * @phpstan-import-type Reply from MockApi
 */
abstract class TestCase extends PHPUnitTestCase
{
    private ?string $environmentKey = null;

    protected function setUp(): void
    {
        // FLASHPI_API_KEY must not leak in from the machine running the tests.
        $value = getenv('FLASHPI_API_KEY');
        $this->environmentKey = \is_string($value) ? $value : null;
        putenv('FLASHPI_API_KEY');
        unset($_ENV['FLASHPI_API_KEY'], $_SERVER['FLASHPI_API_KEY']);
    }

    protected function tearDown(): void
    {
        putenv('FLASHPI_API_KEY' . ($this->environmentKey === null ? '' : '=' . $this->environmentKey));
        unset($_ENV['FLASHPI_API_KEY'], $_SERVER['FLASHPI_API_KEY']);
    }

    /**
     * @param list<Reply> $replies
     * @param array<string, mixed> $settings The Flashpi constructor's named arguments.
     */
    protected static function api(array $replies = [], array $settings = [], ?FakeClock $clock = null): MockApi
    {
        return new MockApi($replies, $settings, $clock);
    }

    /**
     * Checks the one request `$api` sent: its method, its path, its query, its JSON body as sent
     * (null for none) and its Idempotency-Key, which every POST carries and nothing else does
     * (Python: tests/test_resources.py's test_sends_its_operation).
     *
     * @param array<string, string> $query
     */
    protected static function assertSentOnly(MockApi $api, string $method, string $path, array $query = [], ?string $json = null): void
    {
        self::assertCount(1, $api->sent);
        $request = $api->request(0);
        self::assertSame($method, $request->getMethod());
        self::assertSame($path, $request->getUri()->getPath());
        parse_str($request->getUri()->getQuery(), $sent);
        self::assertSame($query, $sent);
        if ($json === null) {
            self::assertSame('', (string) $request->getBody());
            self::assertFalse($request->hasHeader('content-type'));
        } else {
            self::assertSame($json, (string) $request->getBody());
            self::assertSame('application/json', $request->getHeaderLine('content-type'));
        }
        self::assertSame($method === 'POST', $request->hasHeader('idempotency-key'));
    }

    /**
     * The query parameters of each request sent.
     *
     * @return list<array<array-key, mixed>>
     */
    protected static function queries(MockApi $api): array
    {
        return array_map(static function ($request): array {
            parse_str($request->getUri()->getQuery(), $query);

            return $query;
        }, $api->sent);
    }

    /**
     * The exception `$call` throws, checked against a class and, optionally, a pattern of its
     * message.
     *
     * @template E of \Throwable
     *
     * @param class-string<E> $class
     * @param callable(): mixed $call
     *
     * @return E
     */
    protected static function throws(string $class, callable $call, ?string $pattern = null): \Throwable
    {
        try {
            $call();
        } catch (\Throwable $error) {
            self::assertInstanceOf($class, $error, (string) $error);
            if ($pattern !== null) {
                self::assertMatchesRegularExpression($pattern, $error->getMessage());
            }

            return $error;
        }
        self::fail("Nothing was thrown, expected {$class}");
    }
}
