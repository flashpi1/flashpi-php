<?php

declare(strict_types=1);

namespace Flashpi\Tests;

use Flashpi\Version;
use PHPUnit\Framework\TestCase;

/**
 * The version the User-Agent carries is the one release-please releases (Python:
 * tests/test_version.py).
 */
final class VersionTest extends TestCase
{
    private const ROOT = __DIR__ . '/..';

    public function testIsASemanticVersion(): void
    {
        self::assertMatchesRegularExpression('/^\d+\.\d+\.\d+(-[0-9A-Za-z.-]+)?$/', Version::VERSION);
    }

    public function testCarriesTheMarkerReleasePleaseUpdates(): void
    {
        $source = (string) file_get_contents(self::ROOT . '/src/Version.php');
        self::assertMatchesRegularExpression(
            "/public const VERSION = '" . preg_quote(Version::VERSION, '/') . "'; \\/\\/ x-release-please-version/",
            $source,
        );
    }

    public function testMatchesTheVersionReleasePleaseRecorded(): void
    {
        // A wrong extra-files entry, or a hand edit, would ship a stale User-Agent.
        $manifest = json_decode((string) file_get_contents(self::ROOT . '/.release-please-manifest.json'), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($manifest);
        self::assertSame(Version::VERSION, $manifest['.'] ?? null);
        $config = json_decode((string) file_get_contents(self::ROOT . '/release-please-config.json'), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($config);
        $packages = $config['packages'] ?? null;
        self::assertIsArray($packages);
        $package = $packages['.'] ?? null;
        self::assertIsArray($package);
        self::assertSame(['src/Version.php'], $package['extra-files'] ?? null);
        self::assertSame('php', $package['release-type'] ?? null);
    }

    public function testComposerJsonCarriesNoVersion(): void
    {
        // Packagist takes the version from the tag; a version in composer.json would have to match it.
        $composer = json_decode((string) file_get_contents(self::ROOT . '/composer.json'), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($composer);
        self::assertArrayNotHasKey('version', $composer);
    }
}
