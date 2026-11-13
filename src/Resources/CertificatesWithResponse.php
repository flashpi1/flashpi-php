<?php

declare(strict_types=1);

namespace Flashpi\Resources;

use Flashpi\ApiResponse;
use Flashpi\Internal\ResponseView;
use Flashpi\Page;
use Flashpi\Types\ApiTypes;
use Flashpi\Types\SdkTypes;

/**
 * `$flashpi->issuers->certificates->withResponse()`: the same methods, returning an ApiResponse
 * with the HTTP response and the request id.
 *
 * @phpstan-import-type Certificate from ApiTypes
 * @phpstan-import-type CertificateListParams from SdkTypes
 * @phpstan-import-type CertificateUpload from SdkTypes
 * @phpstan-import-type RequestOptions from SdkTypes
 *
 * @method ApiResponse<Certificate> upload(string $issuerId, CertificateUpload $upload, RequestOptions $options = [])
 * @method ApiResponse<Page<Certificate>> list(string $issuerId, CertificateListParams|null $params = null, RequestOptions $options = [])
 * @method ApiResponse<Certificate> get(string $issuerId, string $certificateId, RequestOptions $options = [])
 */
final class CertificatesWithResponse extends ResponseView {}
