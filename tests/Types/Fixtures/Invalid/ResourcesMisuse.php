<?php

declare(strict_types=1);

namespace Flashpi\Tests\Types\Fixtures\Invalid;

use Flashpi\Flashpi;
use Flashpi\Types\ApiTypes;

/**
 * Misuses of the resources PHPStan must report at level max (tests/Types/ConsumerTypesTest.php
 * checks each one by its line; Python: the `pyright: ignore` lines of
 * tests/test_resource_types.py). Excluded from the main analysis.
 *
 * @phpstan-import-type CreateCreditNote from ApiTypes
 * @phpstan-import-type Document from ApiTypes
 */
final class ResourcesMisuse
{
    /**
     * @param CreateCreditNote $note
     *
     * @return Document
     */
    public static function mistakes(Flashpi $flashpi, array $note): array
    {
        $flashpi->documents->list(['state' => 'authorized']);
        $flashpi->documents->list(['status' => 'approved']);
        $flashpi->status->get(['language' => 'fr']);
        $flashpi->status->get(['retries' => 1]);
        $flashpi->issuers->get();
        $flashpi->issuers->certificates->upload('id', ['file' => 'p12']);
        $flashpi->webhookEndpoints->create(['url' => 'https://example.com']);
        $flashpi->invoices->withResponse()->create($note);
        $pdf = $flashpi->documents->pdf('id');
        echo \strlen($pdf['xml']);
        $deleted = $flashpi->issuers->delete('id');
        foreach ($flashpi->documents->list() as $document) {
            echo $document['acessKey'];
        }

        return $flashpi->invoices->create($note);
    }
}
