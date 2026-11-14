<?php

declare(strict_types=1);

namespace Flashpi\Tests;

use PHPUnit\Framework\TestCase;

/**
 * The Composer scripts the workflows run.
 */
final class ComposerScriptsTest extends TestCase
{
    private const ROOT = __DIR__ . '/..';

    public function testRunsTheLiveSuiteWithoutComposersProcessTimeout(): void
    {
        // Composer stops a script after 300 s by default, and the live suite may wait longer for
        // the SRI's test environment (tests/Live/LiveTest.php polls each slow step for up to two
        // minutes): the release pull request would fail on Composer's limit, not on a test.
        $composer = json_decode((string) file_get_contents(self::ROOT . '/composer.json'), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($composer);
        $scripts = $composer['scripts'] ?? null;
        self::assertIsArray($scripts);
        self::assertSame(['Composer\\Config::disableProcessTimeout', 'phpunit --group live'], $scripts['test:live'] ?? null);
        self::assertStringContainsString("run: composer test:live\n", (string) file_get_contents(self::ROOT . '/.github/workflows/live.yml'));
    }
}
