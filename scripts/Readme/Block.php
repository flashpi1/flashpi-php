<?php

declare(strict_types=1);

namespace Flashpi\Scripts\Readme;

/**
 * A ```php block of a README.
 */
final class Block
{
    /**
     * @param string $readme The README's file name.
     * @param int $line The line of the opening fence (1-based).
     * @param string $section The heading the block sits under.
     * @param string $code The code, ending with a line break.
     */
    public function __construct(
        public readonly string $readme,
        public readonly int $line,
        public readonly string $section,
        public readonly string $code,
    ) {}

    public function where(): string
    {
        return "{$this->readme}:{$this->line}";
    }
}
