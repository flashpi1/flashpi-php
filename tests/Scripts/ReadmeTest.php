<?php

declare(strict_types=1);

namespace Flashpi\Tests\Scripts;

use Flashpi\Scripts\Readme\Block;
use Flashpi\Scripts\Readme\Command;
use Flashpi\Scripts\Readme\ExampleError;
use Flashpi\Scripts\Readme\Examples;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The README examples: tests/Examples/Readme/ must hold every ```php block of both READMEs, as
 * files PHPStan analyses (`php scripts/readme.php generate|check`; Python: tests/test_readme.py).
 */
final class ReadmeTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';

    private const CONTEXT = <<<'PHP'
        <?php

        final class Context
        {
            public static function flashpi(): object {}

            public static function documentId(): string {}

            private static function hidden(): void {}
        }

        PHP;

    private const HEADER_LINES = 10;

    private string $root;

    /** @var resource */
    private $stdout;

    /** @var resource */
    private $stderr;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/flashpi-readme-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/' . Examples::DIRECTORY, 0o777, true);
        file_put_contents($this->root . '/' . Examples::CONTEXT, self::CONTEXT);
        file_put_contents($this->root . '/README.md', "# Flashpi\n\n## Estado\n\n```php\necho \$flashpi->status->get()['status'];\n```\n");
        file_put_contents(
            $this->root . '/README.en.md',
            "# Flashpi\n\n```bash\ncomposer require flashpi/flashpi-php\n```\n\n## Status\n\n"
            . "```php\nuse Flashpi\\Flashpi;\n\necho Flashpi::class, \$flashpi::class;\n```\n\n```php\necho 1;\n```\n",
        );
        $this->stdout = self::memory();
        $this->stderr = self::memory();
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root . '/' . Examples::DIRECTORY . '/*') ?: [] as $file) {
            unlink($file);
        }
        foreach (['README.md', 'README.en.md'] as $file) {
            if (is_file("{$this->root}/{$file}")) {
                unlink("{$this->root}/{$file}");
            }
        }
        foreach ([Examples::DIRECTORY, 'tests/Examples', 'tests', ''] as $directory) {
            if (is_dir("{$this->root}/{$directory}")) {
                rmdir("{$this->root}/{$directory}");
            }
        }
    }

    public function testTheCommittedExamplesMatchTheReadmes(): void
    {
        $stdout = self::memory();
        self::assertSame(0, (new Command(self::ROOT, $stdout, self::memory()))->run(['readme.php', 'check']));
        self::assertStringContainsString('matches the READMEs', self::read($stdout));
    }

    public function testNoExampleReadsTheApiKeyWithGetenv(): void
    {
        // The client reads FLASHPI_API_KEY from $_ENV, $_SERVER, then getenv(). Dotenv loaders
        // fill only the first two, where `(string) getenv(...)` would pass an empty key.
        foreach (Examples::READMES as $readme) {
            foreach (Examples::blocks($readme, (string) file_get_contents(self::ROOT . "/{$readme}")) as $block) {
                self::assertStringNotContainsString("getenv('FLASHPI_API_KEY')", $block->code, $block->where());
            }
        }
    }

    public function testRefusesBadArguments(): void
    {
        self::assertSame(2, $this->command('publish'));
        self::assertSame(2, (new Command($this->root, $this->stdout, $this->stderr))->run(['readme.php', 'check', 'extra']));
        self::assertStringContainsString('Usage', self::read($this->stderr));
    }

    public function testGenerateWritesOneFilePerBlockAndCheckFollowsTheReadmes(): void
    {
        self::assertSame(1, $this->command('check'));
        self::assertStringContainsString('es_01.php', self::read($this->stderr));

        self::assertSame(0, (new Command($this->root, $this->stdout, $this->stderr))->run(['readme.php']));
        self::assertSame("Wrote 3 examples to tests/Examples/Readme\n", self::read($this->stdout));
        $names = array_map('basename', glob($this->root . '/' . Examples::DIRECTORY . '/*') ?: []);
        sort($names);
        self::assertSame(['Context.php', 'en_01.php', 'en_02.php', 'es_01.php'], $names);
        self::assertSame(0, $this->command('check'));
        self::assertSame("tests/Examples/Readme matches the READMEs (3 examples)\n", self::read($this->stdout));

        file_put_contents($this->root . '/README.en.md', "# Flashpi\n\n```php\necho 2;\n```\n");
        self::assertSame(1, $this->command('check'));
        self::assertStringContainsString('(en_01.php, en_02.php)', self::read($this->stderr));
        self::assertSame(0, $this->command('generate'));
        self::assertFileDoesNotExist($this->root . '/' . Examples::DIRECTORY . '/en_02.php');
        self::assertFileExists($this->root . '/' . Examples::CONTEXT);
        self::assertSame(0, $this->command('check'));
    }

    public function testABrokenExampleFailsBothModes(): void
    {
        file_put_contents($this->root . '/README.md', "```php\necho (;\n```\n");
        self::assertSame(1, $this->command('generate'));
        self::assertSame(1, $this->command('check'));
        self::assertStringContainsString('README.md:1: the example is not valid PHP', self::read($this->stderr));
        unlink($this->root . '/' . Examples::CONTEXT);
        self::assertSame(1, $this->command('check'));
        self::assertStringContainsString('Could not read tests/Examples/Readme/Context.php', self::read($this->stderr));
    }

    public function testBlocksCarryTheirHeadingAndOtherLanguagesAreSkipped(): void
    {
        $text = "# Flashpi\r\n\r\n## Webhooks\n\n```bash\ncomposer require flashpi/flashpi-php\n```\n\n"
            . "```php\n// A comment, not a heading\necho 1;\n```\n\n### Thin mode\n\n"
            . "```PHP\necho 2;\n```\n\n```\nplain\n```\n\n```neon\nincludes: []\n```\n";
        $blocks = Examples::blocks('README.en.md', $text);
        self::assertSame(
            [[9, 'Webhooks', "// A comment, not a heading\necho 1;\n"], [16, 'Thin mode', "echo 2;\n"]],
            array_map(static fn(Block $block): array => [$block->line, $block->section, $block->code], $blocks),
        );
    }

    public function testAnUnclosedBlockIsAnError(): void
    {
        $this->expectException(ExampleError::class);
        $this->expectExceptionMessage('README.md:2: the code block is never closed');
        Examples::blocks('README.md', "Text\n```php\necho 1;\n");
    }

    public function testTheContextNamesAreItsPublicStaticMethods(): void
    {
        self::assertSame(['flashpi', 'documentId'], Examples::contextNames(self::CONTEXT));
    }

    public function testFindsTheVariablesABlockReadsBeforeSettingThem(): void
    {
        $code = <<<'PHP'
            $flashpi = new Flashpi();
            $page = $flashpi->documents->list();
            foreach ($page as $document) {
                echo $document['id'], $invoice['id'];
            }
            $invoice = []; // set after it was read
            $order /* a comment */ = $body;
            echo $_ENV['X'] ?? '', $documentId === $other ? 1 : 2;

            PHP;
        self::assertSame(['document', 'invoice', 'body', '_ENV', 'documentId', 'other'], Examples::readBeforeSet(self::block($code)));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function uncheckable(): iterable
    {
        yield 'syntax error' => ["echo (;\n", 'README.en.md:3: the example is not valid PHP'];
        yield 'empty' => ["\n\n", 'the example is empty'];
        yield 'opening tag' => ["<?php\necho 1;\n", 'cannot have an opening tag, a namespace or a declare'];
        yield 'namespace' => ["namespace App;\necho 1;\n", 'cannot have an opening tag, a namespace or a declare'];
        yield 'declare' => ["declare(strict_types=1);\necho 1;\n", 'cannot have an opening tag, a namespace or a declare'];
    }

    #[DataProvider('uncheckable')]
    public function testExamplesThatCannotBeCheckedAreErrors(string $code, string $message): void
    {
        $this->expectException(ExampleError::class);
        $this->expectExceptionMessage($message);
        Examples::file(self::block($code), 'en', 1, ['flashpi']);
    }

    public function testTheFileHasItsNamespaceTheImportsThenTheContext(): void
    {
        $code = "use Flashpi\\Flashpi;\nuse GuzzleHttp\\Client;\n\necho \$flashpi::class, \$documentId, Client::class;\n\$order = Flashpi::class;\n";
        $file = Examples::file(self::block($code), 'en', 2, ['flashpi', 'documentId', 'order', 'invoice']);
        self::assertStringStartsWith("<?php\n\n/*\n * Example 2 of README.en.md, under \"Getting started\".\n", $file);
        self::assertSame(
            "declare(strict_types=1);\n\nnamespace Flashpi\\Tests\\Examples\\Readme\\En02;\n\n"
            . "use Flashpi\\Flashpi;\nuse GuzzleHttp\\Client;\n\n"
            . "\$flashpi = \\Flashpi\\Tests\\Examples\\Readme\\Context::flashpi();\n"
            . "\$documentId = \\Flashpi\\Tests\\Examples\\Readme\\Context::documentId();\n\n"
            . "echo \$flashpi::class, \$documentId, Client::class;\n\$order = Flashpi::class;\n",
            self::body($file),
        );
    }

    public function testABlockWithoutImportsOrContextIsKeptAsItIs(): void
    {
        $file = Examples::file(self::block("echo 1;\n\necho 2;\n"), 'es', 14, ['flashpi']);
        self::assertSame("declare(strict_types=1);\n\nnamespace Flashpi\\Tests\\Examples\\Readme\\Es14;\n\necho 1;\n\necho 2;\n", self::body($file));
    }

    public function testAHeadingCannotEndTheComment(): void
    {
        $file = Examples::file(new Block('README.md', 1, 'Say */ bye', "echo 1;\n"), 'es', 1, []);
        self::assertStringContainsString(' * Example 1 of README.md, under "Say *\\/ bye".', $file);
        self::assertSame(1, substr_count($file, '*/'));
    }

    private function command(string $mode): int
    {
        return (new Command($this->root, $this->stdout, $this->stderr))->run(['readme.php', $mode]);
    }

    private static function block(string $code): Block
    {
        return new Block('README.en.md', 3, 'Getting started', $code);
    }

    /**
     * A generated file without its header comment.
     */
    private static function body(string $file): string
    {
        return implode("\n", \array_slice(explode("\n", $file), self::HEADER_LINES));
    }

    /**
     * @return resource
     */
    private static function memory()
    {
        $stream = fopen('php://memory', 'w+');
        self::assertIsResource($stream);

        return $stream;
    }

    /**
     * What a stream received since the last read, which it then forgets.
     *
     * @param resource $stream
     */
    private static function read($stream): string
    {
        rewind($stream);
        $text = (string) stream_get_contents($stream);
        ftruncate($stream, 0);
        rewind($stream);

        return $text;
    }
}
