<?php

declare(strict_types=1);

namespace Flashpi\Tests;

use Flashpi\Version;
use PHPUnit\Framework\TestCase;

final class VersionTest extends TestCase
{
    public function testIsASemanticVersion(): void
    {
        self::assertMatchesRegularExpression('/^\d+\.\d+\.\d+(-[0-9A-Za-z.-]+)?$/', Version::VERSION);
    }

    public function testCarriesTheMarkerReleasePleaseUpdates(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../src/Version.php');
        self::assertMatchesRegularExpression(
            "/public const VERSION = '" . preg_quote(Version::VERSION, '/') . "'; \\/\\/ x-release-please-version/",
            $source,
        );
    }
}
