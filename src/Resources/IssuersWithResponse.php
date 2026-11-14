<?php

declare(strict_types=1);

namespace Flashpi\Resources;

use Flashpi\ApiResponse;
use Flashpi\Internal\ResponseView;
use Flashpi\Page;
use Flashpi\Types\ApiTypes;
use Flashpi\Types\SdkTypes;

/**
 * `$flashpi->issuers->withResponse()`: the same methods, returning an ApiResponse with the HTTP
 * response and the request id.
 *
 * @phpstan-import-type CreateIssuer from ApiTypes
 * @phpstan-import-type Issuer from ApiTypes
 * @phpstan-import-type IssuerListParams from SdkTypes
 * @phpstan-import-type RequestOptions from SdkTypes
 * @phpstan-import-type UpdateIssuer from ApiTypes
 *
 * @method ApiResponse<Issuer> create(CreateIssuer $body, RequestOptions $options = [])
 * @method ApiResponse<Page<Issuer>> list(IssuerListParams|null $params = null, RequestOptions $options = [])
 * @method ApiResponse<Issuer> get(string $issuerId, RequestOptions $options = [])
 * @method ApiResponse<Issuer> update(string $issuerId, UpdateIssuer $body, RequestOptions $options = [])
 * @method ApiResponse<null> delete(string $issuerId, RequestOptions $options = [])
 */
final class IssuersWithResponse extends ResponseView {}
