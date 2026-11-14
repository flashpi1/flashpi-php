<?php

declare(strict_types=1);

namespace Flashpi\Tests\Support;

/**
 * The API's OpenAPI document (openapi/openapi.json), read into its operations.
 */
final class Contract
{
    private const FILE = __DIR__ . '/../../openapi/openapi.json';

    private const METHODS = ['get', 'post', 'patch', 'put', 'delete'];

    /** @var array<string, Operation>|null */
    private static ?array $operations = null;

    private function __construct() {}

    /**
     * Every operation, by operationId.
     *
     * @return array<string, Operation>
     */
    public static function operations(): array
    {
        return self::$operations ??= self::read();
    }

    public static function operation(string $id): Operation
    {
        $operation = self::operations()[$id] ?? null;
        \assert($operation !== null, "No operation {$id}");

        return $operation;
    }

    /**
     * The members of every JSON request body, at any depth: those that are lists, each with the
     * `minItems` of every place it appears, and those that are objects.
     *
     * @return array{lists: array<string, list<mixed>>, objects: list<string>}
     */
    public static function bodyMembers(): array
    {
        $contract = self::map(json_decode((string) file_get_contents(self::FILE), true, 512, \JSON_THROW_ON_ERROR));
        $schemas = self::map(self::map($contract['components'] ?? null)['schemas'] ?? null);
        $lists = [];
        $objects = [];
        $seen = [];
        $resolve = static function (mixed $schema) use ($schemas): array {
            $schema = self::map($schema);
            while (\is_string($schema['$ref'] ?? null)) {
                $schema = self::map($schemas[(string) self::reference($schema)] ?? null);
            }

            return $schema;
        };
        $walk = static function (mixed $schema) use (&$walk, $resolve, &$lists, &$objects, &$seen): void {
            $reference = self::reference($schema);
            if ($reference !== null) {
                if (isset($seen[$reference])) {
                    return;
                }
                $seen[$reference] = true;
            }
            $schema = $resolve($schema);
            foreach (['allOf', 'oneOf', 'anyOf'] as $keyword) {
                foreach (self::list($schema[$keyword] ?? []) as $part) {
                    $walk($part);
                }
            }
            foreach (['items', 'additionalProperties'] as $keyword) {
                if (\is_array($schema[$keyword] ?? null)) {
                    $walk($schema[$keyword]);
                }
            }
            foreach (self::map($schema['properties'] ?? []) as $name => $property) {
                $resolved = $resolve($property);
                $types = (array) ($resolved['type'] ?? []);
                foreach (['allOf', 'oneOf', 'anyOf'] as $keyword) {
                    foreach (self::list($resolved[$keyword] ?? []) as $part) {
                        $types = [...$types, ...(array) ($resolve($part)['type'] ?? [])];
                    }
                }
                if (\in_array('array', $types, true)) {
                    $lists[(string) $name][] = $resolved['minItems'] ?? null;
                }
                if (\in_array('object', $types, true) || isset($resolved['properties'])) {
                    $objects[(string) $name] = true;
                }
                $walk($property);
            }
        };
        foreach (self::map($contract['paths'] ?? null) as $item) {
            foreach (self::map($item) as $method => $spec) {
                if (\in_array($method, self::METHODS, true)) {
                    $content = self::map(self::map(self::map($spec)['requestBody'] ?? [])['content'] ?? []);
                    if (isset($content['application/json'])) {
                        $walk(self::map($content['application/json'])['schema'] ?? null);
                    }
                }
            }
        }
        ksort($lists);
        ksort($objects);

        return ['lists' => $lists, 'objects' => array_keys($objects)];
    }

    /**
     * @return array<string, Operation>
     */
    private static function read(): array
    {
        $contract = self::map(json_decode((string) file_get_contents(self::FILE), true, 512, \JSON_THROW_ON_ERROR));
        $schemas = self::map(self::map($contract['components'] ?? null)['schemas'] ?? null);
        $operations = [];
        foreach (self::map($contract['paths'] ?? null) as $path => $item) {
            $item = self::map($item);
            foreach ($item as $method => $spec) {
                if (!\in_array($method, self::METHODS, true)) {
                    continue;
                }
                $spec = self::map($spec);
                $parameters = [...self::list($item['parameters'] ?? []), ...self::list($spec['parameters'] ?? [])];
                $query = [];
                $idempotent = false;
                foreach ($parameters as $parameter) {
                    $parameter = self::map($parameter);
                    if (($parameter['in'] ?? null) === 'query') {
                        $query[(string) self::text($parameter['name'] ?? null)] = self::map($parameter['schema'] ?? null);
                    }
                    $idempotent = $idempotent || ($parameter['name'] ?? null) === 'Idempotency-Key';
                }
                $requestBody = self::map($spec['requestBody'] ?? []);
                $content = self::map($requestBody['content'] ?? []);
                $bodyType = array_key_first($content);
                $answers = array_filter(self::map($spec['responses'] ?? null), static fn(int|string $code): bool => str_starts_with((string) $code, '2'), \ARRAY_FILTER_USE_KEY);
                ksort($answers);
                $status = (int) array_key_first($answers);
                $answerContent = self::map(self::map(reset($answers))['content'] ?? []);
                $answerType = array_key_first($answerContent);
                $answerSchema = $answerType === null ? null : self::reference(self::map($answerContent[$answerType])['schema'] ?? null);
                $data = $answerSchema === null ? [] : self::map(self::map(self::map($schemas[$answerSchema] ?? [])['properties'] ?? [])['data'] ?? []);
                $id = (string) self::text($spec['operationId'] ?? null);
                $operations[$id] = new Operation(
                    $id,
                    strtoupper($method),
                    (string) $path,
                    $query,
                    $bodyType === null ? null : (string) $bodyType,
                    ($requestBody['required'] ?? null) === true,
                    $bodyType === null ? null : self::reference(self::map($content[$bodyType])['schema'] ?? null),
                    $status,
                    $answerType === null ? null : (string) $answerType,
                    $answerSchema,
                    ($data['type'] ?? null) === 'array' ? self::reference($data['items'] ?? null) : null,
                    $idempotent,
                );
            }
        }

        return $operations;
    }

    /**
     * The name of the component schema a schema refers to, if any.
     */
    private static function reference(mixed $schema): ?string
    {
        $reference = \is_array($schema) ? $schema['$ref'] ?? null : null;

        return \is_string($reference) ? substr($reference, (int) strrpos($reference, '/') + 1) : null;
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function map(mixed $value): array
    {
        \assert(\is_array($value));

        return $value;
    }

    /**
     * @return list<mixed>
     */
    private static function list(mixed $value): array
    {
        \assert(\is_array($value) && array_is_list($value));

        return $value;
    }

    private static function text(mixed $value): ?string
    {
        return \is_string($value) ? $value : null;
    }
}
