<?php

declare(strict_types=1);

namespace Flashpi\Tests\Types;

use Flashpi\Scripts\Readme\Block;
use Flashpi\Scripts\Readme\Examples;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The examples of the public docblocks pass PHPStan as a reader's code would (level max, bleeding
 * edge), like the README examples: readers copy them from their editor.
 *
 * Each ```php block of a doc comment under src/ (src/Internal excepted: its examples are the
 * SDK's own code) becomes a file of its own, in a temporary directory, as the README examples do
 * (scripts/Readme/Examples.php). A variable the block reads before setting it is set first from
 * CONTEXT; any other is an error PHPStan reports. A block that elides code with `[...]` is left
 * out.
 */
#[Group('phpstan')]
final class DocblockExamplesTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';

    private const ELIDED = '[...]';

    /** What the examples take from their surroundings, by variable name. */
    private const CONTEXT = [
        'flashpi' => 'new \\Flashpi\\Flashpi()',
        'issuerId' => "'iss_123'",
        'rawBody' => "(string) file_get_contents('php://input')",
    ];

    public function testFindsTheExamplesOfThePublicDocblocks(): void
    {
        $places = array_map(static fn(Block $block): string => $block->readme, self::examples());
        self::assertContains('src/Resources/Certificates.php', $places);
        self::assertContains('src/Resources/IssuerOnboardings.php', $places);
        self::assertContains('src/Webhooks.php', $places);
        self::assertContains('src/Page.php', $places);
        self::assertNotContains('src/Internal/RequestSpec.php', $places);
    }

    public function testTheExamplesPassPhpstanAtLevelMax(): void
    {
        if (!class_exists('GuzzleHttp\\Exception\\ConnectTimeoutException')) {
            // As tests/Types/ConsumerTypesTest.php: Guzzle 7's psr7 2 installs a getallheaders()
            // polyfill without a return type, which PHPStan reads as mixed.
            self::markTestSkipped('The consumer types are checked with Guzzle 8');
        }
        $directory = sys_get_temp_dir() . '/flashpi-docblocks-' . bin2hex(random_bytes(6));
        mkdir($directory);
        try {
            $places = [];
            foreach (self::examples() as $index => $block) {
                $name = \sprintf('example_%02d.php', $index + 1);
                file_put_contents("{$directory}/{$name}", self::file($block, $index + 1));
                $places[$name] = $block->where();
            }
            file_put_contents("{$directory}/phpstan.neon", self::config($directory));
            $reported = [];
            foreach (self::analyse("{$directory}/phpstan.neon") as [$file, $message]) {
                $reported[] = ($places[$file] ?? $file) . ": {$message}";
            }
            self::assertSame([], $reported);
        } finally {
            foreach (glob("{$directory}/*") ?: [] as $path) {
                unlink($path);
            }
            rmdir($directory);
        }
    }

    /**
     * The ```php blocks of the doc comments of src/, outside src/Internal, but those that elide
     * code. Each block's `readme` is the source file and its `line` the line of the fence.
     *
     * @return list<Block>
     */
    private static function examples(): array
    {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::ROOT . '/src', \FilesystemIterator::SKIP_DOTS));
        $sources = [];
        foreach ($files as $file) {
            \assert($file instanceof \SplFileInfo);
            $path = 'src' . substr($file->getPathname(), \strlen(self::ROOT . '/src'));
            if ($file->getExtension() === 'php' && !str_starts_with($path, 'src/Internal/')) {
                $sources[] = $path;
            }
        }
        sort($sources);
        $examples = [];
        foreach ($sources as $path) {
            foreach (token_get_all((string) file_get_contents(self::ROOT . "/{$path}")) as $token) {
                if (!\is_array($token) || $token[0] !== \T_DOC_COMMENT) {
                    continue;
                }
                // The comment as Markdown: without `/**`, `*/` and the ` * ` of each line.
                $lines = array_map(
                    static fn(string $line): string => (string) preg_replace('/^\s*(\/\*\*|\*\/|\* ?)/', '', $line),
                    explode("\n", $token[1]),
                );
                foreach (Examples::blocks($path, implode("\n", $lines)) as $block) {
                    if (!str_contains($block->code, self::ELIDED)) {
                        $examples[] = new Block($path, $token[2] + $block->line - 1, '', $block->code);
                    }
                }
            }
        }

        return $examples;
    }

    /**
     * The checked file of a block: its namespace, its `use` imports, the variables it takes from
     * CONTEXT, then the rest of the block.
     */
    private static function file(Block $block, int $number): string
    {
        $lines = explode("\n", rtrim($block->code, "\n"));
        $imports = [];
        while ($lines !== [] && (str_starts_with($lines[0], 'use ') || ($imports !== [] && $lines[0] === ''))) {
            $imports[] = array_shift($lines);
        }
        $assignments = [];
        foreach (Examples::readBeforeSet($block) as $name) {
            if (isset(self::CONTEXT[$name])) {
                $assignments[] = "\${$name} = " . self::CONTEXT[$name] . ';';
            }
        }

        return implode("\n", [
            '<?php',
            '',
            "// {$block->where()}",
            '',
            'declare(strict_types=1);',
            '',
            'namespace Flashpi\\Tests\\Docblocks\\Example' . $number . ';',
            '',
            ...$imports,
            '',
            ...$assignments,
            '',
            ...$lines,
            '',
        ]);
    }

    /**
     * A consumer's configuration (tests/Types/Fixtures/phpstan.neon) over the examples.
     */
    private static function config(string $directory): string
    {
        $root = (string) realpath(self::ROOT);

        return implode("\n", [
            'includes:',
            "  - {$root}/vendor/phpstan/phpstan/conf/bleedingEdge.neon",
            '',
            'parameters:',
            '  level: max',
            '  paths:',
            "    - {$directory}",
            '  scanDirectories:',
            "    - {$root}/src",
            "  tmpDir: {$root}/.phpstan.cache/docblocks",
            '',
        ]);
    }

    /**
     * The errors PHPStan reports, as [file name, message].
     *
     * @return list<array{string, string}>
     */
    private static function analyse(string $config): array
    {
        $command = escapeshellarg(\PHP_BINARY) . ' ' . escapeshellarg(self::ROOT . '/vendor/bin/phpstan')
            . ' analyse --error-format=json --no-progress --memory-limit=1G -c ' . escapeshellarg($config) . ' 2>/dev/null';
        $output = shell_exec($command);
        self::assertIsString($output);
        $result = json_decode($output, true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($result);
        self::assertSame([], $result['errors'] ?? null);
        self::assertIsArray($result['files'] ?? null);
        $errors = [];
        foreach ($result['files'] as $path => $file) {
            self::assertIsArray($file);
            self::assertIsArray($file['messages'] ?? null);
            foreach ($file['messages'] as $message) {
                self::assertIsArray($message);
                self::assertIsString($message['message'] ?? null);
                $errors[] = [basename((string) $path), $message['message']];
            }
        }

        return $errors;
    }
}
