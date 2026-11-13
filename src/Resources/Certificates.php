<?php

declare(strict_types=1);

namespace Flashpi\Resources;

use Flashpi\Exception\FlashpiException;
use Flashpi\Internal\CertificateForm;
use Flashpi\Internal\Path;
use Flashpi\Internal\RequestSpec;
use Flashpi\Internal\Resource;
use Flashpi\Page;
use Flashpi\Types\ApiTypes;
use Flashpi\Types\SdkTypes;

/**
 * The .p12 certificates (firmas electrónicas) of an issuer: `$flashpi->issuers->certificates`.
 *
 * @phpstan-import-type Certificate from ApiTypes
 * @phpstan-import-type CertificateListParams from SdkTypes
 * @phpstan-import-type CertificateUpload from SdkTypes
 * @phpstan-import-type RequestOptions from SdkTypes
 */
final class Certificates extends Resource
{
    /**
     * Uploads a .p12 for the issuer, for the mode of the API key only (test or live). To renew
     * the certificate of both modes, start an issuer onboarding with it instead.
     *
     * Flashpi checks it in the background: the certificate starts `processing`, then becomes
     * `accepted` (event `certificate.verified`, or `certificate.pending` while its revocation
     * status cannot be checked) or `rejected` (no event: follow it with get() and read
     * `rejection`).
     *
     * ```php
     * $flashpi->issuers->certificates->upload($issuerId, [
     *     'file' => fopen('firma.p12', 'rb'),
     *     'password' => getenv('P12_PASSWORD'),
     * ]);
     * ```
     *
     * The file is read once, before anything is sent, so every retry sends the same bytes.
     *
     * @param CertificateUpload $upload
     * @param RequestOptions $options
     *
     * @return Certificate
     *
     * @throws FlashpiException
     * @throws \InvalidArgumentException A file that is neither a string nor a readable stream.
     */
    public function upload(string $issuerId, #[\SensitiveParameter] array $upload, array $options = []): array
    {
        $form = CertificateForm::build($upload);
        /** @var Certificate $certificate */
        $certificate = $this->send(new RequestSpec('POST', Path::of('/v1/issuers/{}/certificates', $issuerId), multipart: $form, options: $options));

        return $certificate;
    }

    /**
     * Lists the certificates of an issuer, newest first. Iterate the page with foreach to walk
     * every following page too.
     *
     * @param CertificateListParams|null $params
     * @param RequestOptions $options
     *
     * @return Page<Certificate>
     *
     * @throws FlashpiException
     */
    public function list(string $issuerId, ?array $params = null, array $options = []): Page
    {
        /** @var Page<Certificate> $page */
        $page = $this->paginate(new RequestSpec('GET', Path::of('/v1/issuers/{}/certificates', $issuerId), query: $params, options: $options));

        return $page;
    }

    /**
     * Retrieves a certificate: its status, validity, revocation and, when rejected, why.
     *
     * @param RequestOptions $options
     *
     * @return Certificate
     *
     * @throws FlashpiException
     */
    public function get(string $issuerId, string $certificateId, array $options = []): array
    {
        /** @var Certificate $certificate */
        $certificate = $this->send(new RequestSpec('GET', Path::of('/v1/issuers/{}/certificates/{}', $issuerId, $certificateId), options: $options));

        return $certificate;
    }

    /**
     * The same methods, returning an ApiResponse with the HTTP response and the request id.
     */
    public function withResponse(): CertificatesWithResponse
    {
        return $this->view(CertificatesWithResponse::class);
    }
}
