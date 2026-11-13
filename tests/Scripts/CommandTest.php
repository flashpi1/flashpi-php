<?php

declare(strict_types=1);

namespace Flashpi\Tests\Scripts;

use Flashpi\Scripts\OpenApi\Answer;
use Flashpi\Scripts\OpenApi\Command;
use Flashpi\Scripts\OpenApi\Downloader;
use PHPUnit\Framework\TestCase;

/**
 * `php scripts/openapi.php sync|generate|check`.
 */
final class CommandTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';

    private string $root;

    /** @var resource */
    private $stdout;

    /** @var resource */
    private $stderr;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/flashpi-openapi-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/openapi', 0o777, true);
        copy(self::ROOT . '/openapi/openapi.json', $this->root . '/openapi/openapi.json');
        $this->stdout = self::memory();
        $this->stderr = self::memory();
    }

    protected function tearDown(): void
    {
        foreach (['openapi/openapi.json', 'src/Types/ApiTypes.php'] as $file) {
            if (is_file("{$this->root}/{$file}")) {
                unlink("{$this->root}/{$file}");
            }
        }
        foreach (['src/Types', 'src', 'openapi', ''] as $directory) {
            if (is_dir("{$this->root}/{$directory}")) {
                rmdir("{$this->root}/{$directory}");
            }
        }
    }

    public function testGeneratesChecksAndRefusesBadArguments(): void
    {
        self::assertSame(1, $this->command('check'));
        self::assertSame("src/Types/ApiTypes.php is out of date. Run: composer openapi:generate\n", self::read($this->stderr));
        self::assertSame(0, $this->command());
        self::assertSame("Wrote src/Types/ApiTypes.php\n", self::read($this->stdout));
        self::assertFileEquals(self::ROOT . '/src/Types/ApiTypes.php', "{$this->root}/src/Types/ApiTypes.php");
        self::assertSame(0, $this->command('check'));
        self::assertSame("src/Types/ApiTypes.php matches openapi/openapi.json\n", self::read($this->stdout));
        file_put_contents("{$this->root}/src/Types/ApiTypes.php", "<?php\n// stale\n");
        self::assertSame(1, $this->command('check'));
        self::assertSame(2, $this->command('push'));
        self::assertSame(2, $this->command('check', 'extra'));
        self::assertStringContainsString('Usage: php scripts/openapi.php sync|generate|check', self::read($this->stderr));
    }

    public function testTheScriptChecksTheCommittedTypes(): void
    {
        $output = [];
        exec(\PHP_BINARY . ' ' . escapeshellarg(self::ROOT . '/scripts/openapi.php') . ' check 2>&1', $output, $code);
        self::assertSame(['src/Types/ApiTypes.php matches openapi/openapi.json'], $output);
        self::assertSame(0, $code);
    }

    public function testSyncCopiesTheDownloadAddingTheFinalNewlineThenGenerates(): void
    {
        $contract = rtrim((string) file_get_contents(self::ROOT . '/openapi/openapi.json'));
        file_put_contents("{$this->root}/openapi/openapi.json", '{}');
        $downloader = new Downloader([], static fn(string $url): Answer => new Answer(200, null, $contract));
        self::assertSame(0, $this->command('sync', downloader: $downloader));
        self::assertSame(
            'Copied ' . Downloader::DEFAULT_URL . " to openapi/openapi.json\nWrote src/Types/ApiTypes.php\n",
            self::read($this->stdout),
        );
        self::assertSame($contract . "\n", file_get_contents("{$this->root}/openapi/openapi.json"));
    }

    public function testSyncRefusesWhatIsNotAnOpenApiDocument(): void
    {
        foreach (['<html>', '[1]', '{"openapi": "3.1.0"}'] as $body) {
            $downloader = new Downloader([], static fn(string $url): Answer => new Answer(200, null, $body));
            self::assertSame(1, $this->command('sync', downloader: $downloader));
            self::assertSame("The downloaded file is not an OpenAPI document\n", self::read($this->stderr));
        }
        $refused = new Downloader(['FLASHPI_OPENAPI_URL' => 'http://x'], static fn(string $url): Answer => new Answer(200, null, ''));
        self::assertSame(1, $this->command('sync', downloader: $refused));
        self::assertStringContainsString('non-HTTPS', self::read($this->stderr));
        self::assertFileEquals(self::ROOT . '/openapi/openapi.json', "{$this->root}/openapi/openapi.json");
    }

    public function testReportsAMissingOrBrokenContract(): void
    {
        file_put_contents("{$this->root}/openapi/openapi.json", '{"openapi": "3.0.0"}');
        self::assertSame(1, $this->command('generate'));
        self::assertSame("Only OpenAPI 3.1 documents are supported\n", self::read($this->stderr));
        unlink("{$this->root}/openapi/openapi.json");
        self::assertSame(1, $this->command('generate'));
        self::assertSame("Could not read openapi/openapi.json\n", self::read($this->stderr));
    }

    private function command(?string $mode = null, ?string $extra = null, ?Downloader $downloader = null): int
    {
        $downloader ??= new Downloader([], static fn(string $url): Answer => new Answer(500, null, ''));
        $arguments = array_values(array_filter(['openapi.php', $mode, $extra], static fn(?string $value): bool => $value !== null));

        return (new Command($this->root, $downloader, $this->stdout, $this->stderr))->run($arguments);
    }

    /**
     * @return resource
     */
    private static function memory()
    {
        $stream = fopen('php://memory', 'w+b');
        self::assertIsResource($stream);

        return $stream;
    }

    /**
     * What a stream received since the last read.
     *
     * @param resource $stream
     */
    private static function read($stream): string
    {
        rewind($stream);
        $contents = (string) stream_get_contents($stream);
        ftruncate($stream, 0);
        rewind($stream);

        return $contents;
    }
}
