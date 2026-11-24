<?php

declare(strict_types=1);

namespace Flashpi\Resources;

use Flashpi\Exception\FlashpiException;
use Flashpi\Internal\CertificateForm;
use Flashpi\Internal\Path;
use Flashpi\Internal\RequestSpec;
use Flashpi\Internal\Resource;
use Flashpi\Types\ApiTypes;
use Flashpi\Types\SdkTypes;

/**
 * Creates an issuer from its .p12 (`$flashpi->issuerOnboardings`): upload the certificate, wait
 * while the onboarding is `verifying` until it is `ready` (Flashpi reads the holder, RUC and SRI
 * data) or `rejected`, review the prefill, then complete it.
 *
 * Needs an account-scoped API key with read_write access.
 *
 * @phpstan-import-type CertificateUpload from SdkTypes
 * @phpstan-import-type CompletedIssuerOnboarding from ApiTypes
 * @phpstan-import-type CompleteIssuerOnboarding from ApiTypes
 * @phpstan-import-type IssuerOnboarding from ApiTypes
 * @phpstan-import-type RequestOptions from SdkTypes
 */
final class IssuerOnboardings extends Resource
{
    /**
     * Starts an onboarding with a .p12 and its password. It starts `verifying`.
     *
     * ```php
     * $onboarding = $flashpi->issuerOnboardings->create([
     *     'file' => (string) file_get_contents('firma.p12'),
     *     'password' => (string) getenv('P12_PASSWORD'),
     * ]);
     * ```
     *
     * The file is read once, before anything is sent, so every retry sends the same bytes.
     *
     * @param CertificateUpload $upload
     * @param RequestOptions $options
     *
     * @return IssuerOnboarding
     *
     * @throws FlashpiException
     * @throws \InvalidArgumentException A file that is neither a string nor a readable stream.
     */
    public function create(#[\SensitiveParameter] array $upload, array $options = []): array
    {
        $form = CertificateForm::build($upload);
        /** @var IssuerOnboarding $onboarding */
        $onboarding = $this->send(new RequestSpec('POST', '/v1/issuer-onboardings', multipart: $form, options: $options));

        return $onboarding;
    }

    /**
     * Retrieves an onboarding, to follow it while it is `verifying`.
     *
     * @param RequestOptions $options
     *
     * @return IssuerOnboarding
     *
     * @throws FlashpiException
     */
    public function get(string $onboardingId, array $options = []): array
    {
        /** @var IssuerOnboarding $onboarding */
        $onboarding = $this->send(new RequestSpec('GET', Path::of('/v1/issuer-onboardings/{}', $onboardingId), options: $options));

        return $onboarding;
    }

    /**
     * Completes a `ready` onboarding.
     *
     * - For a new RUC, `$body` is the issuer to create (as in `$flashpi->issuers->create()`):
     *   `legalName`, `headOfficeAddress` and `taxProfile` are required. Build it from
     *   `$onboarding['prefill']`; the API does not apply the prefill by itself.
     * - When `$onboarding['existingIssuer']` is set, `$body` can be left out (`{}` is sent): the
     *   certificate is added to that issuer for both modes (a renewal) and the issuer is left as
     *   it is.
     * - Send `ruc` when `$onboarding['rucNeedsConfirmation']` is true.
     * - A test key (`fp_test_`) renews only an issuer without live documents: otherwise the API
     *   answers 403 `live_key_required`.
     *
     * @param CompleteIssuerOnboarding|null $body
     * @param RequestOptions $options
     *
     * @return CompletedIssuerOnboarding
     *
     * @throws FlashpiException
     */
    public function complete(string $onboardingId, ?array $body = null, array $options = []): array
    {
        /** @var CompletedIssuerOnboarding $completed */
        $completed = $this->send(new RequestSpec('POST', Path::of('/v1/issuer-onboardings/{}/complete', $onboardingId), body: $body ?? [], options: $options));

        return $completed;
    }

    /**
     * The same methods, returning an ApiResponse with the HTTP response and the request id.
     */
    public function withResponse(): IssuerOnboardingsWithResponse
    {
        return $this->view(IssuerOnboardingsWithResponse::class);
    }
}
