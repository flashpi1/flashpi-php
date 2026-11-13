<?php

declare(strict_types=1);

namespace Flashpi\Resources;

use Flashpi\ApiResponse;
use Flashpi\Internal\ResponseView;
use Flashpi\Types\ApiTypes;
use Flashpi\Types\SdkTypes;

/**
 * `$flashpi->issuerOnboardings->withResponse()`: the same methods, returning an ApiResponse with
 * the HTTP response and the request id.
 *
 * @phpstan-import-type CertificateUpload from SdkTypes
 * @phpstan-import-type CompletedIssuerOnboarding from ApiTypes
 * @phpstan-import-type CompleteIssuerOnboarding from ApiTypes
 * @phpstan-import-type IssuerOnboarding from ApiTypes
 * @phpstan-import-type RequestOptions from SdkTypes
 *
 * @method ApiResponse<IssuerOnboarding> create(CertificateUpload $upload, RequestOptions $options = [])
 * @method ApiResponse<IssuerOnboarding> get(string $onboardingId, RequestOptions $options = [])
 * @method ApiResponse<CompletedIssuerOnboarding> complete(string $onboardingId, CompleteIssuerOnboarding|null $body = null, RequestOptions $options = [])
 */
final class IssuerOnboardingsWithResponse extends ResponseView {}
