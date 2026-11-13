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
