<?php

declare(strict_types=1);

namespace Flashpi\Resources;

use Flashpi\ApiResponse;
use Flashpi\Internal\ResponseView;
use Flashpi\Types\ApiTypes;
use Flashpi\Types\SdkTypes;

/**
 * `$flashpi->withholdings->withResponse()`: the same methods, returning an ApiResponse with the HTTP
 * response and the request id.
 *
 * @phpstan-import-type CreateWithholding from ApiTypes
 * @phpstan-import-type Document from ApiTypes
 * @phpstan-import-type RequestOptions from SdkTypes
 *
 * @method ApiResponse<Document> create(CreateWithholding $body, RequestOptions $options = [])
 */
final class WithholdingsWithResponse extends ResponseView {}
