<?php

declare(strict_types=1);

namespace Flashpi\Resources;

use Flashpi\ApiResponse;
use Flashpi\Internal\ResponseView;
use Flashpi\Page;
use Flashpi\Types\ApiTypes;
use Flashpi\Types\SdkTypes;

/**
 * `$flashpi->issuers->establishments->emissionPoints->withResponse()`: the same methods,
 * returning an ApiResponse with the HTTP response and the request id.
 *
 * @phpstan-import-type CreateEmissionPoint from ApiTypes
 * @phpstan-import-type EmissionPoint from ApiTypes
 * @phpstan-import-type EmissionPointListParams from SdkTypes
 * @phpstan-import-type RequestOptions from SdkTypes
 *
 * @method ApiResponse<EmissionPoint> create(string $issuerId, string $establishmentId, CreateEmissionPoint $body, RequestOptions $options = [])
 * @method ApiResponse<Page<EmissionPoint>> list(string $issuerId, string $establishmentId, EmissionPointListParams|null $params = null, RequestOptions $options = [])
 * @method ApiResponse<EmissionPoint> get(string $issuerId, string $establishmentId, string $emissionPointId, RequestOptions $options = [])
 * @method ApiResponse<null> delete(string $issuerId, string $establishmentId, string $emissionPointId, RequestOptions $options = [])
 */
final class EmissionPointsWithResponse extends ResponseView {}
