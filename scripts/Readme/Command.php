<?php

declare(strict_types=1);

namespace Flashpi\Scripts\Readme;

/**
 * `php scripts/readme.php generate|check`: keeps tests/Examples/Readme/ in step with the READMEs.
 */
final class Command
{
    /**
     * @param resource $stdout
     * @param resource $stderr
     */
    public function __construct(
        private readonly string $root,
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
        if (!\in_array($mode, ['generate', 'check'], true) || \count($argv) > 2) {
            fwrite($this->stderr, "Usage: php scripts/readme.php generate|check\n");

            return 2;
        }
        try {
            $expected = Examples::expected($this->root);
        } catch (ExampleError $error) {
            fwrite($this->stderr, $error->getMessage() . "\n");

            return 1;
        }
        $current = Examples::current($this->root);
        if ($mode === 'check') {
            $stale = [];
            foreach (array_keys($expected + $current) as $name) {
                if (!isset($current[$name], $expected[$name]) || file_get_contents($current[$name]) !== $expected[$name]) {
                    $stale[] = $name;
                }
            }
            sort($stale);
            if ($stale !== []) {
                fwrite($this->stderr, Examples::DIRECTORY . ' is out of date (' . implode(', ', $stale) . "). Run: composer readme:generate\n");

                return 1;
            }
            fwrite($this->stdout, Examples::DIRECTORY . ' matches the READMEs (' . \count($expected) . " examples)\n");

            return 0;
        }
        foreach ($current as $name => $path) {
            if (!isset($expected[$name])) {
                unlink($path);
            }
        }
        foreach ($expected as $name => $text) {
            file_put_contents("{$this->root}/" . Examples::DIRECTORY . "/{$name}", $text);
        }
        fwrite($this->stdout, 'Wrote ' . \count($expected) . ' examples to ' . Examples::DIRECTORY . "\n");

        return 0;
    }
}
