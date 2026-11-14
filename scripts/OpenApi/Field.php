<?php

declare(strict_types=1);

namespace Flashpi\Scripts\OpenApi;

/**
 * A member of an array shape.
 */
final class Field
{
    public function __construct(
        public readonly string $name,
        public readonly string $type,
        public readonly bool $required,
    ) {}
}
