<?php

declare(strict_types=1);

namespace Flashpi\Tests\Examples\Readme;

/**
 * An order of the reader's shop, in the README examples.
 */
final class Order
{
    public function __construct(public readonly string $id) {}
}
