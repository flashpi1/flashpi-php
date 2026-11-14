<?php

declare(strict_types=1);

namespace Flashpi\Scripts\OpenApi;

/**
 * One `@phpstan-type`: an array shape (`fields`) or an alias of another type (`expression`).
 */
final class Definition
{
    /**
     * @param list<Field> $fields
     * @param list<string> $dependencies The definitions it refers to.
     * @param bool $open Whether members the document does not declare may appear.
     */
    public function __construct(
        public readonly string $name,
        public readonly ?string $expression,
        public array $fields = [],
        public array $dependencies = [],
        public readonly bool $open = false,
    ) {}
}
