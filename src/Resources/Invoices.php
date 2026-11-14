<?php

declare(strict_types=1);

namespace Flashpi\Resources;

use Flashpi\Exception\FlashpiException;
use Flashpi\Internal\RequestSpec;
use Flashpi\Internal\Resource;
use Flashpi\Types\ApiTypes;
use Flashpi\Types\SdkTypes;

/**
 * Invoices (facturas): `$flashpi->invoices`.
 *
 * @phpstan-import-type CreateInvoice from ApiTypes
 * @phpstan-import-type Document from ApiTypes
 * @phpstan-import-type RequestOptions from SdkTypes
 */
final class Invoices extends Resource
{
    /**
     * Issues an invoice. The answer is the document: `processing` right away (202), or, with
     * `options.waitForAuthorization`, its outcome once the SRI decides (200), unless the wait
     * ends first (202 with `processing` or `pending_sri`; follow it with webhooks or
     * `$flashpi->documents->get()`).
     *
     * Retries reuse the same Idempotency-Key, and when a retry finds the document already
     * created by an earlier attempt, its current state is returned instead of an error. While
     * waiting for the SRI, each attempt may take the wait plus 15 seconds, even when the timeout
     * is shorter.
     *
     * @param CreateInvoice $body
     * @param RequestOptions $options
     *
     * @return Document
     *
     * @throws FlashpiException
     */
    public function create(array $body, array $options = []): array
    {
        /** @var Document $document */
        $document = $this->send(RequestSpec::issueDocument('/v1/invoices', $body, $options));

        return $document;
    }

    /**
     * The same methods, returning an ApiResponse with the HTTP response and the request id.
     */
    public function withResponse(): InvoicesWithResponse
    {
        return $this->view(InvoicesWithResponse::class);
    }
}
