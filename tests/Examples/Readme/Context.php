<?php

declare(strict_types=1);

namespace Flashpi\Tests\Examples\Readme;

use Flashpi\Flashpi;
use Flashpi\Types\ApiTypes;

/**
 * What the README examples take from their surroundings: the client and the invoice of "Getting
 * started", and stand-ins for the reader's own data.
 *
 * scripts/readme.php sets each variable an example reads before setting it from the method of the
 * same name. Like the examples, it is only analysed, never run.
 *
 * @phpstan-import-type CreateInvoice from ApiTypes
 * @phpstan-import-type Document from ApiTypes
 */
final class Context
{
    private function __construct() {}

    /**
     * The client of "Getting started".
     */
    public static function flashpi(): Flashpi
    {
        return new Flashpi();
    }

    /**
     * The invoice issued in "Getting started".
     *
     * @return Document
     */
    public static function invoice(): array
    {
        throw new \LogicException('The README examples are never run');
    }

    /**
     * An invoice to issue.
     *
     * @return CreateInvoice
     */
    public static function body(): array
    {
        throw new \LogicException('The README examples are never run');
    }

    /**
     * The order an invoice is issued for.
     */
    public static function order(): Order
    {
        return new Order('42');
    }

    /**
     * The id of a document.
     */
    public static function documentId(): string
    {
        return 'doc_1';
    }
}
