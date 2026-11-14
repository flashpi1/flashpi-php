<?php

declare(strict_types=1);

namespace Flashpi\Resources;

use Flashpi\ApiResponse;
use Flashpi\Internal\ResponseView;
use Flashpi\Page;
use Flashpi\Types\ApiTypes;
use Flashpi\Types\SdkTypes;

/**
 * `$flashpi->issuers->establishments->withResponse()`: the same methods, returning an
 * ApiResponse with the HTTP response and the request id.
 *
 * @phpstan-import-type CreateEstablishment from ApiTypes
 * @phpstan-import-type Establishment from ApiTypes
 * @phpstan-import-type EstablishmentListParams from SdkTypes
 * @phpstan-import-type RequestOptions from SdkTypes
 * @phpstan-import-type UpdateEstablishment from ApiTypes
 *
 * @method ApiResponse<Establishment> create(string $issuerId, CreateEstablishment $body, RequestOptions $options = [])
 * @method ApiResponse<Page<Establishment>> list(string $issuerId, EstablishmentListParams|null $params = null, RequestOptions $options = [])
 * @method ApiResponse<Establishment> get(string $issuerId, string $establishmentId, RequestOptions $options = [])
 * @method ApiResponse<Establishment> update(string $issuerId, string $establishmentId, UpdateEstablishment $body, RequestOptions $options = [])
 * @method ApiResponse<null> delete(string $issuerId, string $establishmentId, RequestOptions $options = [])
 */
final class EstablishmentsWithResponse extends ResponseView {}
