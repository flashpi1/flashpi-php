<?php

declare(strict_types=1);

namespace Flashpi\Tests\Types;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The types catch a consumer's mistakes: PHPStan at level max (with bleeding edge, whose array
 * shapes are sealed) reports a misspelled key, a value outside an enum, a missing required key and
 * a value of the wrong type, in the request bodies, the options, the filters and the models, and
 * a resource method called with the wrong arguments or whose answer is misread.
 *
 * It runs with a consumer's configuration (Fixtures/phpstan.neon), not the repository's, so the
 * consumer files must pass as a reader's own code would, and nothing but the expected mistakes
 * may be reported.
 */
#[Group('phpstan')]
final class ConsumerTypesTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';

    private const FIXTURES = __DIR__ . '/Fixtures/Invalid/';

    public function testPhpstanReportsEveryMistakeOfTheMisuseFixtures(): void
    {
        $types = 'TypesMisuse.php';
        $resources = 'ResourcesMisuse.php';
        $expected = [
            [$types, "\$document['acessKey']", "Offset 'acessKey' does not exist on array{id: string"],
            [$types, "'expectedTotl'", "Sealed array shape does not accept array with extra key 'expectedTotl'."],
            [$types, "'dni'", "does not accept type array{idType: 'dni'}"],
            [$types, "self::issue(['issuerId'", "Array does not have offset 'customer'."],
            [$types, "'sequential' => '7'", "Offset 'sequential' (int) does not accept type string."],
            [$types, "'idempotencykey'", "Sealed array shape does not accept array with extra key 'idempotencykey'."],
            [$types, "'fr']", "Offset 'language' ('en'|'es'|null) does not accept type 'fr'."],
            [$types, "language: 'fr'", "expects 'en'|'es'|null, 'fr' given."],
            [$types, "\$event['data']['challenge']", "Offset 'challenge' might not exist on"],
            [$types, "\$event['data']['challenge']", 'Access to protected property Flashpi\\Exception\\ApiException::$code.'],
            [$resources, "'state' => 'authorized'", "Sealed array shape does not accept array with extra key 'state'."],
            [$resources, "'status' => 'approved'", "Offset 'status' ('authorized'|'failed'|'pending_sri'|'processing'|'rejected'|'voided') does not accept type 'approved'."],
            [$resources, "'language' => 'fr'", "Offset 'language' ('en'|'es'|null) does not accept type 'fr'."],
            [$resources, "'retries' => 1", "Sealed array shape does not accept array with extra key 'retries'."],
            [$resources, '->issuers->get()', 'Method Flashpi\\Resources\\Issuers::get() invoked with 0 parameters, 1-2 required.'],
            [$resources, "['file' => 'p12']", "Array does not have offset 'password'."],
            [$resources, "['url' => 'https://example.com']", "Array does not have offset 'enabledEvents'."],
            [$resources, 'withResponse()->create($note)', 'Parameter #1 $body of method Flashpi\\Resources\\InvoicesWithResponse::create() expects'],
            [$resources, "\$pdf['xml']", "Offset 'xml' does not exist on string."],
            [$resources, '$deleted =', 'Result of method Flashpi\\Resources\\Issuers::delete() (void) is used.'],
            [$resources, "\$document['acessKey']", "Offset 'acessKey' does not exist on array{id: string"],
            [$resources, 'return $flashpi->invoices->create($note)', 'Parameter #1 $body of method Flashpi\\Resources\\Invoices::create() expects'],
        ];
        $reported = self::analyse();
        foreach ($expected as [$file, $needle, $fragment]) {
            $line = self::line($file, $needle);
            $matching = array_filter(
                $reported,
                static fn(array $error): bool => $error[0] === $file && $error[1] === $line && str_contains($error[2], $fragment),
            );
            self::assertNotEmpty($matching, "No error on {$file}:{$line} with: {$fragment}\n" . print_r($reported, true));
        }
        $places = static function (array $errors): array {
            $places = array_values(array_unique(array_map(static fn(array $error): string => "{$error[0]}:{$error[1]}", $errors)));
            sort($places);

            return $places;
        };
        $expectedPlaces = array_map(static fn(array $entry): array => [$entry[0], self::line($entry[0], $entry[1])], $expected);
        self::assertSame($places($expectedPlaces), $places($reported), 'PHPStan reported errors on other lines');
    }

    /**
     * The errors of the fixtures as [file name, line, message and tip].
     *
     * @return list<array{string, int, string}>
     */
    private static function analyse(): array
    {
        $command = escapeshellarg(\PHP_BINARY) . ' ' . escapeshellarg(self::ROOT . '/vendor/bin/phpstan')
            . ' analyse --error-format=json --no-progress --memory-limit=1G -c '
            . escapeshellarg(__DIR__ . '/Fixtures/phpstan.neon') . ' 2>/dev/null';
        $output = shell_exec($command);
        self::assertIsString($output);
        $result = json_decode($output, true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($result);
        self::assertIsArray($result['files'] ?? null);
        $errors = [];
        foreach ($result['files'] as $path => $file) {
            self::assertIsArray($file);
            self::assertIsArray($file['messages'] ?? null);
            foreach ($file['messages'] as $message) {
                self::assertIsArray($message);
                self::assertIsInt($message['line'] ?? null);
                self::assertIsString($message['message'] ?? null);
                $tip = $message['tip'] ?? '';
                $errors[] = [basename((string) $path), $message['line'], $message['message'] . ' ' . (\is_string($tip) ? $tip : '')];
            }
        }

        return $errors;
    }

    private static function line(string $file, string $needle): int
    {
        $lines = file(self::FIXTURES . $file);
        self::assertIsArray($lines);
        foreach ($lines as $index => $text) {
            if (str_contains($text, $needle)) {
                return $index + 1;
            }
        }
        self::fail("{$needle} is not in {$file}");
    }
}
