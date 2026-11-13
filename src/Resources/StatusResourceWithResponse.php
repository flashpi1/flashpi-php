<?php

declare(strict_types=1);

namespace Flashpi\Resources;

use Flashpi\ApiResponse;
use Flashpi\Internal\ResponseView;
use Flashpi\Types\ApiTypes;
use Flashpi\Types\SdkTypes;

/**
 * `$flashpi->status->withResponse()`: the same methods, returning an ApiResponse with the HTTP
 * response and the request id.
 *
 * @phpstan-import-type Status from ApiTypes
 * @phpstan-import-type RequestOptions from SdkTypes
 *
 * @method ApiResponse<Status> get(RequestOptions $options = [])
 */
final class StatusResourceWithResponse extends ResponseView {}
