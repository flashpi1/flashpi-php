<?php

declare(strict_types=1);

namespace Flashpi\Tests\Support;

/**
 * Local servers on free ports, for the tests that need real sockets. Each answers one request at
 * a time: an answer a test gave up on ends at its next write.
 *
 * - url(): a `php -S` server whose router is server.php.
 * - rawUrl(): raw_server.php, which writes the status line and the headers itself, so they can
 *   trickle in.
 */
final class LocalServer
{
    /** @var array<string, resource> */
    private static array $processes = [];

    /** @var array<string, string> */
    private static array $urls = [];

    private function __construct() {}

    /**
     * The base URL of the `php -S` server, started on first use.
     */
    public static function url(): string
    {
        return self::start('php', static fn(int $port): array => [\PHP_BINARY, '-S', "127.0.0.1:{$port}", __DIR__ . '/server.php']);
    }

    /**
     * The base URL of the raw server, started on first use.
     */
    public static function rawUrl(): string
    {
        return self::start('raw', static fn(int $port): array => [\PHP_BINARY, __DIR__ . '/raw_server.php', (string) $port]);
    }

    public static function stop(): void
    {
        foreach (self::$processes as $process) {
            proc_terminate($process, 9);
            proc_close($process);
        }
        self::$processes = [];
        self::$urls = [];
    }

    /**
     * @param \Closure(int): list<string> $command
     */
    private static function start(string $kind, \Closure $command): string
    {
        if (isset(self::$urls[$kind])) {
            return self::$urls[$kind];
        }
        $probe = stream_socket_server('tcp://127.0.0.1:0');
        \assert($probe !== false);
        $name = (string) stream_socket_get_name($probe, false);
        fclose($probe);
        $port = (int) substr($name, (int) strrpos($name, ':') + 1);
        $process = proc_open(
            $command($port),
            [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
        );
        \assert(\is_resource($process));
        self::$processes[$kind] = $process;
        self::$urls[$kind] = "http://127.0.0.1:{$port}";
        for ($tries = 0; $tries < 100; $tries++) {
            $connection = @fsockopen('127.0.0.1', $port, $code, $message, 0.1);
            if ($connection !== false) {
                fclose($connection);

                return self::$urls[$kind];
            }
            usleep(50_000);
        }

        throw new \RuntimeException("The local server did not start on port {$port}");
    }
}
