<?php

declare(strict_types=1);

namespace Flashpi\Tests\Scripts;

use Flashpi\Scripts\Package\Archive;
use PHPUnit\Framework\TestCase;

/**
 * The check of what Composer installs from a tag (scripts/package_check.php), on hand-made
 * archives (Python: tests/test_package_check.py).
 */
final class PackageCheckTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';

    private const SOURCES = ['src/Flashpi.php', 'src/Internal/Engine.php'];

    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/flashpi-package-' . bin2hex(random_bytes(6));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        foreach (glob("{$this->directory}/*") ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->directory);
    }

    public function testAcceptsTheLibraryWithItsLicenseAndReadmes(): void
    {
        self::assertSame([], Archive::problems(self::files(), self::SOURCES));
        self::assertSame([], Archive::problems([...self::files(), 'CHANGELOG.md' => '# Changelog'], self::SOURCES));
    }

    public function testReportsWhatIsMissingAndWhatIsExtra(): void
    {
        $files = self::files();
        unset($files['src/Internal/Engine.php'], $files['LICENSE']);
        $files['tests/ClientTest.php'] = '<?php';
        $files['phpunit.xml.dist'] = '<phpunit/>';
        self::assertSame([
            'Missing from the archive: LICENSE',
            'Missing from the archive: src/Internal/Engine.php',
            'Unexpected in the archive: tests/ClientTest.php',
            'Unexpected in the archive: phpunit.xml.dist',
        ], Archive::problems($files, self::SOURCES));
    }

    public function testChecksComposerJson(): void
    {
        $composer = self::composer();
        $composer['version'] = '0.1.0';
        $composer['require']['psr/log'] = '^3.0';
        $composer['name'] = 'flashpi/other';
        $composer['autoload']['files'] = ['src/functions.php'];
        self::assertSame([
            'composer.json must name flashpi/flashpi-php',
            'composer.json must carry no version: Packagist takes it from the tag',
            'composer.json must require {"php":"^8.3","ext-json":"*","guzzlehttp/guzzle":"^7.15.2 || ^8.3"} only',
            'composer.json must autoload Flashpi\\ from src/ only',
        ], Archive::problems([...self::files(), 'composer.json' => (string) json_encode($composer)], self::SOURCES));
        self::assertSame(['composer.json is not a JSON object'], Archive::problems([...self::files(), 'composer.json' => 'null'], self::SOURCES));
    }

    public function testRefusesARelativeLinkInTheReadmePackagistShows(): void
    {
        $readme = "# Flashpi\n\nSee [the docs](docs/decisions.md) and ![logo](./logo.png).\n";
        self::assertSame([
            'README.md links to docs/decisions.md: use an absolute URL, since packagist.org cannot follow a relative link',
            'README.md links to ./logo.png: use an absolute URL, since packagist.org cannot follow a relative link',
        ], Archive::problems([...self::files(), 'README.md' => $readme], self::SOURCES));
    }

    public function testFindsRelativeLinksOutsideCode(): void
    {
        $markdown = "[a](https://x.ec) [b](#errors) [c](mailto:a@x.ec) [d]( <LICENSE> )\n"
            . "```php\n\$a = [\$b](c);\n```\n[e]: CONTRIBUTING.md\n   [f]: http://x.ec\n";
        self::assertSame(['LICENSE', 'CONTRIBUTING.md'], Archive::relativeLinks($markdown));
    }

    public function testReadsATarWithItsTopDirectoryOrAZipWithout(): void
    {
        $tar = new \PharData("{$this->directory}/package.tar");
        $zip = new \PharData("{$this->directory}/package.zip");
        foreach (self::files() as $name => $contents) {
            $tar->addFromString("flashpi-php/{$name}", $contents);
            $zip->addFromString($name, $contents);
        }
        $expected = self::files();
        ksort($expected);
        self::assertSame($expected, Archive::read("{$this->directory}/package.tar"));
        self::assertSame($expected, Archive::read("{$this->directory}/package.zip"));
    }

    public function testListsThePhpFilesOfTheLibrary(): void
    {
        $sources = Archive::sources(self::ROOT);
        self::assertContains('src/Flashpi.php', $sources);
        self::assertContains('src/Types/ApiTypes.php', $sources);
        foreach ($sources as $source) {
            self::assertMatchesRegularExpression('/^src\/[\w\/]+\.php$/', $source);
        }
    }

    /**
     * A complete archive.
     *
     * @return array<string, string>
     */
    private static function files(): array
    {
        return [
            'LICENSE' => 'MIT',
            'README.md' => '# Flashpi',
            'README.en.md' => '# Flashpi',
            'composer.json' => (string) json_encode(self::composer()),
            'src/Flashpi.php' => '<?php',
            'src/Internal/Engine.php' => '<?php',
        ];
    }

    /**
     * @return array{name: string, require: array<string, string>, autoload: array<string, mixed>}
     */
    private static function composer(): array
    {
        return [
            'name' => 'flashpi/flashpi-php',
            'require' => ['php' => '^8.3', 'ext-json' => '*', 'guzzlehttp/guzzle' => '^7.15.2 || ^8.3'],
            'autoload' => ['psr-4' => ['Flashpi\\' => 'src/']],
        ];
    }
}
