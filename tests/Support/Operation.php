<?php

declare(strict_types=1);

namespace Flashpi\Tests\Support;

/**
 * An operation as the OpenAPI document describes it.
 */
final class Operation
{
    /**
     * @param array<string, array<mixed>> $query Query parameters: name to schema.
     * @param string|null $bodyType Content type of the request body, if any.
     * @param string|null $bodySchema The component schema of a JSON body.
     * @param int $status The first success status.
     * @param string|null $answerType Content type of the success answer (null for 204).
     * @param string|null $answerSchema The component schema of a JSON answer.
     * @param string|null $itemSchema The component schema of a list's items.
     * @param bool $idempotentPost Whether the operation takes an Idempotency-Key header.
     */
    public function __construct(
        public readonly string $id,
        public readonly string $method,
        public readonly string $path,
        public readonly array $query,
        public readonly ?string $bodyType,
        public readonly bool $bodyRequired,
        public readonly ?string $bodySchema,
        public readonly int $status,
        public readonly ?string $answerType,
        public readonly ?string $answerSchema,
        public readonly ?string $itemSchema,
        public readonly bool $idempotentPost,
    ) {}
}
