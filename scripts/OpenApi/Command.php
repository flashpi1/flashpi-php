<?php

declare(strict_types=1);

namespace Flashpi\Scripts\OpenApi;

/**
 * `php scripts/openapi.php sync|generate|check`: keeps the SDK's copy of the contract and the
 * types generated from it in step.
 */
final class Command
{
    public const CONTRACT = 'openapi/openapi.json';

    public const TYPES = 'src/Types/ApiTypes.php';

    /**
     * @param resource $stdout
     * @param resource $stderr
     */
    public function __construct(
        private readonly string $root,
        private readonly Downloader $downloader,
        private readonly mixed $stdout,
        private readonly mixed $stderr,
    ) {}

    /**
     * @param list<string> $argv
     *
     * @return int The exit code.
     */
    public function run(array $argv): int
    {
        $mode = $argv[1] ?? 'generate';
        if (!\in_array($mode, ['sync', 'generate', 'check'], true) || \count($argv) > 2) {
            fwrite($this->stderr, "Usage: php scripts/openapi.php sync|generate|check\n");

            return 2;
        }
        try {
            if ($mode === 'sync') {
                $this->sync();
            }
            $contract = @file_get_contents($this->path(self::CONTRACT));
            if ($contract === false) {
                throw new \RuntimeException('Could not read ' . self::CONTRACT);
            }
            $types = Generator::fromContract($contract);
        } catch (\RuntimeException $error) {
            fwrite($this->stderr, $error->getMessage() . "\n");

            return 1;
        }
        if ($mode === 'check') {
            $current = is_file($this->path(self::TYPES)) ? file_get_contents($this->path(self::TYPES)) : '';
            if ($current !== $types) {
                fwrite($this->stderr, self::TYPES . " is out of date. Run: composer openapi:generate\n");

                return 1;
            }
            fwrite($this->stdout, self::TYPES . ' matches ' . self::CONTRACT . "\n");

            return 0;
        }
        $directory = \dirname($this->path(self::TYPES));
        if (!is_dir($directory)) {
            mkdir($directory, 0o777, true);
        }
        file_put_contents($this->path(self::TYPES), $types);
        fwrite($this->stdout, 'Wrote ' . self::TYPES . "\n");

        return 0;
    }

    private function sync(): void
    {
        [$source, $contract] = $this->downloader->download();
        try {
            $parsed = json_decode($contract, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $parsed = null;
        }
        if (!\is_array($parsed) || !\array_key_exists('openapi', $parsed) || !\array_key_exists('paths', $parsed)) {
            throw new \RuntimeException('The downloaded file is not an OpenAPI document');
        }
        file_put_contents(
            $this->path(self::CONTRACT),
            str_ends_with($contract, "\n") ? $contract : $contract . "\n",
        );
        fwrite($this->stdout, "Copied {$source} to " . self::CONTRACT . "\n");
    }

    private function path(string $relative): string
    {
        return $this->root . '/' . $relative;
    }
}
