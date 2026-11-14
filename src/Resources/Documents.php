<?php

declare(strict_types=1);

namespace Flashpi\Resources;

use Flashpi\Exception\ConflictException;
use Flashpi\Exception\FlashpiException;
use Flashpi\Exception\RateLimitException;
use Flashpi\Internal\Path;
use Flashpi\Internal\RequestSpec;
use Flashpi\Internal\Resource;
use Flashpi\Page;
use Flashpi\Types\ApiTypes;
use Flashpi\Types\SdkTypes;

/**
 * Every issued document, of any type: search, XML, RIDE (PDF) and email (`$flashpi->documents`).
 *
 * @phpstan-import-type Document from ApiTypes
 * @phpstan-import-type DocumentListParams from SdkTypes
 * @phpstan-import-type DocumentXml from SdkTypes
 * @phpstan-import-type DocumentXmlKind from SdkTypes
 * @phpstan-import-type RequestOptions from SdkTypes
 */
final class Documents extends Resource
{
    private const XML_ACCEPT = 'application/xml, application/problem+json';

    private const PDF_ACCEPT = 'application/pdf, application/problem+json';

    /** @var list<DocumentXmlKind> */
    private const XML_KINDS = ['authorized', 'signed', 'unsigned'];

    /**
     * Lists documents, newest first, with optional filters. Iterate the page with foreach to
     * walk every following page too.
     *
     * @param DocumentListParams|null $params
     * @param RequestOptions $options
     *
     * @return Page<Document>
     *
     * @throws FlashpiException
     */
    public function list(?array $params = null, array $options = []): Page
    {
        /** @var Page<Document> $page */
        $page = $this->paginate(new RequestSpec('GET', '/v1/documents', query: $params, options: $options));

        return $page;
    }

    /**
     * Retrieves a document with its tax status, SRI messages, RIDE and email state.
     *
     * @param RequestOptions $options
     *
     * @return Document
     *
     * @throws FlashpiException
     */
    public function get(string $documentId, array $options = []): array
    {
        /** @var Document $document */
        $document = $this->send(new RequestSpec('GET', Path::of('/v1/documents/{}', $documentId), options: $options));

        return $document;
    }

    /**
     * The document's XML: the authorized one once the SRI authorizes it, otherwise the signed or
     * unsigned one (see `kind`, from the Flashpi-Xml-Kind header).
     *
     * @param RequestOptions $options
     *
     * @return DocumentXml
     *
     * @throws FlashpiException
     */
    public function xml(string $documentId, array $options = []): array
    {
        $result = $this->sendForResponse(new RequestSpec(
            'GET',
            Path::of('/v1/documents/{}/xml', $documentId),
            accept: self::XML_ACCEPT,
            responseType: 'text',
            options: $options,
        ));
        $kind = $result->response->getHeaderLine('flashpi-xml-kind');

        return [
            'xml' => \is_string($result->data) ? $result->data : '',
            'kind' => \in_array($kind, self::XML_KINDS, true) ? $kind : null,
        ];
    }

    /**
     * The RIDE (the printable PDF) of an authorized document, as its bytes. Throws a
     * ConflictException with the error code `ride_not_available` while it is still being
     * generated.
     *
     * @param RequestOptions $options
     *
     * @throws ConflictException
     * @throws FlashpiException
     */
    public function pdf(string $documentId, array $options = []): string
    {
        $pdf = $this->send(new RequestSpec(
            'GET',
            Path::of('/v1/documents/{}/pdf', $documentId),
            accept: self::PDF_ACCEPT,
            responseType: 'bytes',
            options: $options,
        ));

        return \is_string($pdf) ? $pdf : '';
    }

    /**
     * Emails the authorized document (RIDE and XML) again to the address of its request. The new
     * delivery shows in `email.deliveries`; daily limits apply (RateLimitException).
     *
     * @param RequestOptions $options
     *
     * @return Document
     *
     * @throws RateLimitException
     * @throws FlashpiException
     */
    public function sendEmail(string $documentId, array $options = []): array
    {
        /** @var Document $document */
        $document = $this->send(new RequestSpec('POST', Path::of('/v1/documents/{}/email', $documentId), body: [], options: $options));

        return $document;
    }

    /**
     * The same methods, returning an ApiResponse with the HTTP response and the request id.
     */
    public function withResponse(): DocumentsWithResponse
    {
        return $this->view(DocumentsWithResponse::class);
    }
}
