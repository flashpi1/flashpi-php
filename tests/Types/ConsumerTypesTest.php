<?php

declare(strict_types=1);

namespace Flashpi\Tests\Types;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The types catch a consumer's mistakes: PHPStan at level max (with bleeding edge, whose array
 * shapes are sealed) reports a misspelled key, a value outside an enum, a missing required key and
 * a value of the wrong type, in the request bodies, the options and the models.
 */
#[Group('phpstan')]
final class ConsumerTypesTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';

    private const FIXTURE = __DIR__ . '/Fixtures/Invalid/TypesMisuse.php';

    public function testPhpstanReportsEveryMistakeOfTheMisuseFixture(): void
    {
        $expected = [
            [self::line("\$document['acessKey']"), "Offset 'acessKey' does not exist on array{id: string"],
            [self::line("'expectedTotl'"), "Sealed array shape does not accept array with extra key 'expectedTotl'."],
            [self::line("'dni'"), "does not accept type array{idType: 'dni'}"],
            [self::line("self::issue(['issuerId'"), "Array does not have offset 'customer'."],
            [self::line("'sequential' => '7'"), "Offset 'sequential' (int) does not accept type string."],
            [self::line("'idempotencykey'"), "Sealed array shape does not accept array with extra key 'idempotencykey'."],
            [self::line("'fr'"), "Offset 'language' ('en'|'es'|null) does not accept type 'fr'."],
        ];
        $reported = self::analyse();
        foreach ($expected as [$line, $fragment]) {
            $matching = array_filter(
                $reported,
                static fn(array $error): bool => $error[0] === $line && str_contains($error[1], $fragment),
            );
            self::assertNotEmpty($matching, "No error on line {$line} with: {$fragment}\n" . print_r($reported, true));
        }
        $lines = array_unique(array_column($reported, 0));
        sort($lines);
        $expectedLines = array_unique(array_column($expected, 0));
        sort($expectedLines);
        self::assertSame($expectedLines, $lines, 'PHPStan reported errors on other lines');
    }

    /**
     * The errors of the fixture as [line, message and tip].
     *
     * @return list<array{int, string}>
     */
    private static function analyse(): array
    {
        $command = escapeshellarg(\PHP_BINARY) . ' ' . escapeshellarg(self::ROOT . '/vendor/bin/phpstan')
            . ' analyse --error-format=json --no-progress --memory-limit=1G -c '
            . escapeshellarg(__DIR__ . '/Fixtures/Invalid/phpstan.neon') . ' 2>/dev/null';
        $output = shell_exec($command);
        self::assertIsString($output);
        $result = json_decode($output, true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($result);
        self::assertIsArray($result['files'] ?? null);
        $errors = [];
        foreach ($result['files'] as $file) {
            self::assertIsArray($file);
            self::assertIsArray($file['messages'] ?? null);
            foreach ($file['messages'] as $message) {
                self::assertIsArray($message);
                self::assertIsInt($message['line'] ?? null);
                self::assertIsString($message['message'] ?? null);
                $tip = $message['tip'] ?? '';
                $errors[] = [$message['line'], $message['message'] . ' ' . (\is_string($tip) ? $tip : '')];
            }
        }

        return $errors;
    }

    private static function line(string $needle): int
    {
        $lines = file(self::FIXTURE);
        self::assertIsArray($lines);
        foreach ($lines as $index => $text) {
            if (str_contains($text, $needle)) {
                return $index + 1;
            }
        }
        self::fail("{$needle} is not in the fixture");
    }
}
