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
