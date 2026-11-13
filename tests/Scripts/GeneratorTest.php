<?php

declare(strict_types=1);

namespace Flashpi\Tests\Scripts;

use Flashpi\Scripts\OpenApi\GenerationError;
use Flashpi\Scripts\OpenApi\Generator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The generator of src/Types/ApiTypes.php (Python: tests/test_openapi.py).
 */
final class GeneratorTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';

    public function testTheGeneratedTypesMatchTheCommittedContract(): void
    {
        $contract = (string) file_get_contents(self::ROOT . '/openapi/openapi.json');
        self::assertSame(
            (string) file_get_contents(self::ROOT . '/src/Types/ApiTypes.php'),
            Generator::fromContract($contract),
        );
    }

    public function testTheOutputIsDeterministic(): void
    {
        $contract = (string) file_get_contents(self::ROOT . '/openapi/openapi.json');
        self::assertSame(Generator::fromContract($contract), Generator::fromContract($contract));
    }

    /**
     * Every member of every schema has the type, the optional mark and the nullability of the
     * document.
     */
    public function testEachObjectSchemaIsTranslatedFaithfully(): void
    {
        $aliases = self::aliases();
        $checked = 0;
        foreach (self::schemas() as $name => $schema) {
            if (!\array_key_exists('properties', $schema)) {
                continue;
            }
            $properties = self::object($schema['properties']);
            $fields = self::fields(self::alias($aliases, $name));
            self::assertSame(array_keys($properties), array_keys($fields), $name);
            $required = self::strings($schema['required'] ?? []);
            foreach ($properties as $key => $value) {
                $member = self::object($value);
                $where = "{$name}.{$key}";
                [$type, $optional] = $fields[$key] ?? ['', false];
                self::assertSame(!\in_array($key, $required, true), $optional, $where);
                $declared = $member['type'] ?? null;
                $kinds = \is_string($declared) ? [$declared] : self::strings($declared ?? []);
                $nullable = \in_array('null', $kinds, true)
                    || \in_array(['type' => 'null'], self::items($member['anyOf'] ?? []), true);
                self::assertSame($nullable, str_ends_with($type, '|null'), "{$where}: {$type}");
                $kind = array_values(array_diff($kinds, ['null']))[0] ?? null;
                $suffix = $nullable ? '|null' : '';
                if (\array_key_exists('$ref', $member)) {
                    $target = substr(self::text($member['$ref']), \strlen('#/components/schemas/'));
                    $open = \in_array($target, ['ErrorCode', 'FieldErrorCode'], true);
                    self::assertSame($open ? "{$target}|string" : $target, $type, $where);
                } elseif (\array_key_exists('enum', $member)) {
                    $alias = $name . ucfirst($key);
                    self::assertSame($alias . $suffix, $type, $where);
                    $literals = [];
                    foreach (self::items($member['enum']) as $literal) {
                        if ($literal !== null) {
                            $literals[] = "'" . self::text($literal) . "'";
                        }
                    }
                    self::assertSame(implode('|', $literals), self::flat(self::alias($aliases, $alias)), $where);
                } elseif (\array_key_exists('const', $member)) {
                    self::assertSame("'" . self::text($member['const']) . "'", $type, $where);
                } elseif ($kind === 'array') {
                    self::assertStringStartsWith('list<', $type, $where);
                } elseif ($kind !== null && $kind !== 'object') {
                    $scalars = ['string' => 'string', 'integer' => 'int', 'number' => 'int|float', 'boolean' => 'bool'];
                    self::assertSame(($scalars[$kind] ?? '?') . $suffix, $type, $where);
                }
                $checked++;
            }
        }
        self::assertGreaterThan(400, $checked);
    }

    public function testEachListQueryIsAShapeOfOptionalMembers(): void
    {
        $aliases = self::aliases();
        $queries = [];
        foreach (self::object(self::contract()['paths'] ?? null) as $item) {
            foreach (self::object($item) as $value) {
                $operation = self::object($value);
                $names = [];
                foreach (self::items($operation['parameters'] ?? []) as $parameter) {
                    $parameter = self::object($parameter);
                    if (($parameter['in'] ?? null) === 'query') {
                        $names[] = self::text($parameter['name'] ?? null);
                    }
                }
                if ($names !== []) {
                    $queries[self::text($operation['operationId'] ?? null)] = $names;
                }
            }
        }
        $operations = array_keys($queries);
        sort($operations);
        self::assertSame([
            'certificatesList',
            'documentsList',
            'emissionPointsList',
            'establishmentsList',
            'eventsList',
            'issuersList',
            'webhookEndpointsDeliveriesList',
            'webhookEndpointsList',
        ], $operations);
        foreach ($queries as $operationId => $names) {
            $fields = self::fields(self::alias($aliases, ucfirst($operationId) . 'Query'));
            self::assertSame($names, array_keys($fields), $operationId);
            self::assertSame([true], array_values(array_unique(array_column($fields, 1))), $operationId);
        }
        self::assertSame(
            "'invoice'|'purchase_settlement'|'credit_note'|'debit_note'|'waybill'|'withholding'",
            self::flat(self::alias($aliases, 'DocumentsListQueryType')),
        );
    }

    public function testEventsAreAUnionDiscriminatedByType(): void
    {
        $aliases = self::aliases();
        self::assertSame('DocumentEvent|CertificateEvent|AccountEvent', self::alias($aliases, 'Event'));
        $event = self::object(self::schemas()['Event'] ?? null);
        $mapping = self::object(self::object($event['discriminator'] ?? null)['mapping'] ?? null);
        self::assertCount(26, $mapping);
        foreach ($mapping as $type => $target) {
            $member = substr(self::text($target), \strlen('#/components/schemas/'));
            self::assertStringContainsString("'{$type}'", self::flat(self::alias($aliases, $member . 'Type')));
        }
        self::assertArrayNotHasKey('data', self::fields(self::alias($aliases, 'EventThin')));
    }

    public function testKeepsErrorCodesOpenAndProblemDetailsUnsealed(): void
    {
        $aliases = self::aliases();
        self::assertSame(['ErrorCode|string', false], self::fields(self::alias($aliases, 'ProblemDetails'))['code'] ?? null);
        self::assertSame(['FieldErrorCode|string', false], self::fields(self::alias($aliases, 'FieldError'))['code'] ?? null);
        self::assertStringEndsWith("\n *     ...\n * }", self::alias($aliases, 'ProblemDetails'));
        self::assertStringStartsWith("'bad_request'", self::alias($aliases, 'ErrorCode'));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function unsupported(): iterable
    {
        yield 'unknown keyword' => [['A' => ['type' => 'object', 'properties' => ['x' => ['type' => 'string', 'if' => []]]]], 'unsupported keywords if'];
        yield 'allOf of two' => [['A' => ['allOf' => [['type' => 'string'], ['type' => 'integer']]]], 'one member'];
        yield 'several types' => [['A' => ['type' => ['string', 'integer']]], 'several non-null types'];
        yield 'not on a string' => [['A' => ['type' => 'string', 'not' => ['const' => 'x']]], 'only supported on objects'];
        yield 'not that is not required' => [['A' => ['type' => 'object', 'not' => ['const' => 1], 'properties' => ['x' => ['type' => 'string']]]], 'not.required'];
        yield 'declared and forbidden' => [['A' => ['type' => 'object', 'not' => ['required' => ['x']], 'properties' => ['x' => ['type' => 'string']]]], 'declared and forbidden'];
        yield 'required without schema' => [['A' => ['type' => 'object', 'properties' => ['y' => ['type' => 'string']], 'required' => ['x']]], 'without a schema'];
        yield 'unknown reference' => [['A' => ['$ref' => '#/components/schemas/B']], 'unknown reference'];
        yield 'reference with siblings' => [['A' => ['$ref' => '#/components/schemas/A', 'type' => 'string']], 'sibling'];
        yield 'float enum' => [['A' => ['type' => 'string', 'enum' => [1.5]]], 'enum values'];
        yield 'enum of null' => [['A' => ['type' => 'string', 'enum' => [null]]], 'no values'];
        yield 'unknown type' => [['A' => ['type' => 'tuple']], 'unsupported type'];
        yield 'anyOf with type' => [['A' => ['anyOf' => [['type' => 'string']], 'type' => 'object']], 'anyOf with type'];
        yield 'string with properties' => [['A' => ['type' => 'string', 'properties' => ['x' => ['type' => 'string']]]], 'mixed with other types'];
        yield 'enum with properties' => [['A' => ['enum' => ['a'], 'properties' => ['x' => ['type' => 'string']]]], 'mixed with other types'];
        yield 'two definitions' => [['A' => ['type' => 'object', 'properties' => ['x' => ['type' => 'string', 'enum' => ['a']]]], 'AX' => ['type' => 'string']], 'Two definitions'];
        yield 'reserved name' => [['List' => ['type' => 'string']], 'cannot be the name'];
        yield 'invalid name' => [['A-B' => ['type' => 'string']], 'cannot be the name'];
        yield 'recursion' => [['A' => ['type' => 'object', 'properties' => ['b' => ['$ref' => '#/components/schemas/A']]]], 'refers to itself'];
        yield 'discriminant not allowed' => [[
            'A' => ['type' => 'object', 'properties' => ['t' => ['type' => 'string', 'enum' => ['a']]]],
            'U' => ['oneOf' => [['$ref' => '#/components/schemas/A']], 'discriminator' => ['propertyName' => 't', 'mapping' => ['b' => '#/components/schemas/A']]],
        ], 'does not allow b'];
        yield 'mapping outside the union' => [[
            'A' => ['type' => 'object', 'properties' => ['t' => ['type' => 'string']]],
            'B' => ['type' => 'object', 'properties' => ['t' => ['type' => 'string']]],
            'U' => ['oneOf' => [['$ref' => '#/components/schemas/A']], 'discriminator' => ['propertyName' => 't', 'mapping' => ['b' => '#/components/schemas/B']]],
        ], 'outside the union'];
        yield 'discriminated inline objects' => [[
            'U' => ['oneOf' => [['type' => 'object', 'properties' => ['t' => ['type' => 'string']]]], 'discriminator' => ['propertyName' => 't']],
        ], 'references only'];
        yield 'typed members and extras' => [['A' => ['type' => 'object', 'properties' => ['x' => ['type' => 'string']], 'additionalProperties' => ['type' => 'string']]], 'typed members'];
        yield 'schema that is a list' => [['A' => [['type' => 'string']]], 'expected a schema object'];
        yield 'enum that is not a list' => [['A' => ['type' => 'string', 'enum' => ['a' => 'b']]], 'expected a list'];
        yield 'type that is not text' => [['A' => ['type' => [1]]], 'expected a string'];
    }

    /**
     * @param array<string, mixed> $schemas
     */
    #[DataProvider('unsupported')]
    public function testUnsupportedConstructsFailLoudly(array $schemas, string $message): void
    {
        $this->expectException(GenerationError::class);
        $this->expectExceptionMessage($message);
        (new Generator(self::document($schemas)))->generate();
    }

    public function testRefusesOtherOpenApiVersionsAndBrokenJson(): void
    {
        try {
            (new Generator(['openapi' => '3.0.3', 'paths' => []]))->generate();
            self::fail('3.0 was accepted');
        } catch (GenerationError $error) {
            self::assertSame('Only OpenAPI 3.1 documents are supported', $error->getMessage());
        }
        $this->expectException(GenerationError::class);
        $this->expectExceptionMessage('The contract is not JSON');
        Generator::fromContract('{');
    }

    public function testRefusesUnknownParameterReferences(): void
    {
        $paths = ['/x' => ['get' => ['operationId' => 'x', 'parameters' => [['$ref' => '#/nowhere']]]]];
        $this->expectException(GenerationError::class);
        $this->expectExceptionMessage('unknown parameter');
        (new Generator(['openapi' => '3.1.0', 'paths' => $paths]))->generate();
    }

    public function testQuotesQueryNamesThatAreNotIdentifiersAndReadsParameterReferences(): void
    {
        $parameters = [
            ['name' => 'from-date', 'in' => 'query', 'required' => true, 'schema' => ['type' => 'string']],
            ['$ref' => '#/components/parameters/Limit'],
            ['name' => 'id', 'in' => 'path', 'schema' => ['type' => 'string']],
        ];
        $document = [
            'openapi' => '3.1.0',
            'paths' => ['/x' => ['parameters' => [], 'get' => ['operationId' => 'thingsList', 'parameters' => $parameters], 'post' => ['operationId' => 'thingsCreate']]],
            'components' => ['parameters' => ['Limit' => ['name' => 'limit', 'in' => 'query', 'schema' => ['type' => 'integer']]]],
        ];
        $source = (new Generator($document))->generate();
        self::assertStringContainsString(" * @phpstan-type ThingsListQuery array{\n *     'from-date': string,\n *     limit?: int,\n * }", $source);
        self::assertStringNotContainsString('ThingsCreateQuery', $source);
    }

    public function testTranslatesTheOtherSupportedConstructs(): void
    {
        $long = [];
        foreach (['Bag', 'Free', 'Empty', 'ALongEnoughNameForTheUnionToGoBeyondTheLimit', 'Anything'] as $name) {
            $long[] = ['$ref' => "#/components/schemas/{$name}"];
        }
        $source = (new Generator(self::document([
            'Bag' => ['type' => 'object', 'additionalProperties' => ['type' => 'integer']],
            'Anything' => ['type' => 'object'],
            'Free' => ['type' => 'object', 'additionalProperties' => true],
            'Flag' => ['type' => ['boolean', 'null'], 'const' => true, 'description' => 'Say "yes"'],
            'Plain' => ['description' => 'Any JSON value.'],
            'Nothing' => ['type' => 'null'],
            'Empty' => ['type' => 'object', 'properties' => []],
            'Open' => ['type' => 'object', 'properties' => [], 'additionalProperties' => true],
            'Quoted' => ['type' => 'string', 'enum' => ["it's", 'a\\b', 7, false, null]],
            'Numbers' => ['type' => 'array', 'items' => ['type' => ['number', 'null']]],
            'Choice' => ['oneOf' => [['$ref' => '#/components/schemas/Bag'], ['$ref' => '#/components/schemas/Bag']]],
            'Wrapped' => ['allOf' => [['$ref' => '#/components/schemas/Bag']]],
            'Item' => ['type' => 'object', 'properties' => ['code' => ['$ref' => '#/components/schemas/ErrorCode'], 'tags' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => ['x']]]]],
            'ErrorCode' => ['type' => 'string', 'enum' => ['a']],
            'ALongEnoughNameForTheUnionToGoBeyondTheLimit' => ['type' => 'string'],
            'Long' => ['anyOf' => [...$long, ['type' => 'string'], ['type' => 'null']]],
        ])))->generate();
        $expected = [
            ' * @phpstan-type Bag array<string, int>',
            ' * @phpstan-type Anything array<string, mixed>',
            ' * @phpstan-type Free array<string, mixed>',
            ' * @phpstan-type Flag true|null',
            ' * @phpstan-type Plain mixed',
            ' * @phpstan-type Nothing null',
            ' * @phpstan-type Empty array{}',
            " * @phpstan-type Open array{\n *     ...\n * }",
            " * @phpstan-type Quoted 'it\\'s'|'a\\\\b'|7|false|null",
            ' * @phpstan-type Numbers list<int|float|null>',
            ' * @phpstan-type Choice Bag',
            ' * @phpstan-type Wrapped Bag',
            " * @phpstan-type Item array{\n *     code?: ErrorCode|string,\n *     tags?: list<ItemTagsItem>,\n * }",
            " * @phpstan-type ItemTagsItem 'x'",
            " * @phpstan-type Long Bag\n *     |Free\n *     |Empty\n *     |ALongEnoughNameForTheUnionToGoBeyondTheLimit\n *     |Anything\n *     |string\n *     |null\n",
        ];
        foreach ($expected as $fragment) {
            self::assertStringContainsString($fragment, $source);
        }
    }

    /**
     * @param array<string, mixed> $schemas
     *
     * @return array<string, mixed>
     */
    private static function document(array $schemas): array
    {
        return ['openapi' => '3.1.0', 'paths' => [], 'components' => ['schemas' => $schemas]];
    }

    /**
     * @return array<string, mixed>
     */
    private static function contract(): array
    {
        $contract = (string) file_get_contents(self::ROOT . '/openapi/openapi.json');

        return self::object(json_decode($contract, true, 512, \JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function schemas(): array
    {
        $components = self::object(self::contract()['components'] ?? null);
        $schemas = [];
        foreach (self::object($components['schemas'] ?? null) as $name => $schema) {
            $schemas[$name] = self::object($schema);
        }

        return $schemas;
    }

    /**
     * Each `@phpstan-type` of ApiTypes.php: its name and its type, as written.
     *
     * @return array<string, string>
     */
    private static function aliases(): array
    {
        $source = (string) file_get_contents(self::ROOT . '/src/Types/ApiTypes.php');
        preg_match_all('/^ \* @phpstan-type (\w+) (.*?)(?=^ \* @phpstan-type |^ \*\/)/ms', $source, $matches, \PREG_SET_ORDER);
        $aliases = [];
        foreach ($matches as $match) {
            $aliases[$match[1]] = rtrim($match[2], "\n");
        }

        return $aliases;
    }

    /**
     * @param array<string, string> $aliases
     */
    private static function alias(array $aliases, string $name): string
    {
        $alias = $aliases[$name] ?? null;
        self::assertNotNull($alias, "No alias {$name}");

        return $alias;
    }

    /**
     * The members of a shape: key => [type, optional].
     *
     * @return array<string, array{string, bool}>
     */
    private static function fields(string $shape): array
    {
        preg_match_all("/^ \\*     ('[^']+'|\\w+)(\\??): (.+),$/m", $shape, $matches, \PREG_SET_ORDER);
        $fields = [];
        foreach ($matches as $match) {
            $fields[trim($match[1], "'")] = [$match[3], $match[2] === '?'];
        }

        return $fields;
    }

    private static function flat(string $type): string
    {
        return str_replace("\n *     ", '', $type);
    }

    /**
     * @return array<string, mixed>
     */
    private static function object(mixed $value): array
    {
        self::assertIsArray($value);
        $object = [];
        foreach ($value as $key => $member) {
            $object[(string) $key] = $member;
        }

        return $object;
    }

    /**
     * @return list<mixed>
     */
    private static function items(mixed $value): array
    {
        self::assertIsArray($value);
        self::assertTrue(array_is_list($value));

        return $value;
    }

    /**
     * @return list<string>
     */
    private static function strings(mixed $value): array
    {
        return array_map(self::text(...), self::items($value));
    }

    private static function text(mixed $value): string
    {
        self::assertIsString($value);

        return $value;
    }
}
