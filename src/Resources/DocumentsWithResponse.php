<?php

declare(strict_types=1);

namespace Flashpi\Resources;

use Flashpi\ApiResponse;
use Flashpi\Internal\ResponseView;
use Flashpi\Page;
use Flashpi\Types\ApiTypes;
use Flashpi\Types\SdkTypes;

/**
 * `$flashpi->documents->withResponse()`: the same methods, returning an ApiResponse with the
 * HTTP response and the request id.
 *
 * @phpstan-import-type Document from ApiTypes
 * @phpstan-import-type DocumentListParams from SdkTypes
 * @phpstan-import-type DocumentXml from SdkTypes
 * @phpstan-import-type RequestOptions from SdkTypes
 *
 * @method ApiResponse<Page<Document>> list(DocumentListParams|null $params = null, RequestOptions $options = [])
 * @method ApiResponse<Document> get(string $documentId, RequestOptions $options = [])
 * @method ApiResponse<DocumentXml> xml(string $documentId, RequestOptions $options = [])
 * @method ApiResponse<string> pdf(string $documentId, RequestOptions $options = [])
 * @method ApiResponse<Document> sendEmail(string $documentId, RequestOptions $options = [])
 */
final class DocumentsWithResponse extends ResponseView {}
