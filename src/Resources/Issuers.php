<?php

declare(strict_types=1);

namespace Flashpi\Resources;

use Flashpi\Exception\ConflictException;
use Flashpi\Exception\FlashpiException;
use Flashpi\Internal\Engine;
use Flashpi\Internal\Path;
use Flashpi\Internal\RequestSpec;
use Flashpi\Internal\Resource;
use Flashpi\Page;
use Flashpi\Types\ApiTypes;
use Flashpi\Types\SdkTypes;

/**
 * The issuers (RUCs) of the account, with their establishments, emission points and .p12
 * certificates (`$flashpi->issuers`). Needs an account-scoped API key with read_write access,
 * also to read.
 *
 * @phpstan-import-type CreateIssuer from ApiTypes
 * @phpstan-import-type Issuer from ApiTypes
 * @phpstan-import-type IssuerListParams from SdkTypes
 * @phpstan-import-type RequestOptions from SdkTypes
 * @phpstan-import-type UpdateIssuer from ApiTypes
 */
final class Issuers extends Resource
{
    private const ISSUERS = '/v1/issuers';

    private const ISSUER = '/v1/issuers/{}';

    /** Establishments of an issuer, with their emission points. */
    public readonly Establishments $establishments;

    /** The .p12 certificates of an issuer. */
    public readonly Certificates $certificates;

    /**
     * @internal Built by the client.
     */
    public function __construct(Engine $engine)
    {
        parent::__construct($engine);
        $this->establishments = new Establishments($engine);
        $this->certificates = new Certificates($engine);
    }

    /**
     * Creates an issuer. To start from its .p12 instead, see `$flashpi->issuerOnboardings`.
     *
     * @param CreateIssuer $body
     * @param RequestOptions $options
     *
     * @return Issuer
     *
     * @throws FlashpiException
     */
    public function create(array $body, array $options = []): array
    {
        /** @var Issuer $issuer */
        $issuer = $this->send(new RequestSpec('POST', self::ISSUERS, body: $body, options: $options));

        return $issuer;
    }

    /**
     * Lists the issuers, newest first. Iterate the page with foreach to walk every following page
     * too.
     *
     * @param IssuerListParams|null $params
     * @param RequestOptions $options
     *
     * @return Page<Issuer>
     *
     * @throws FlashpiException
     */
    public function list(?array $params = null, array $options = []): Page
    {
        /** @var Page<Issuer> $page */
        $page = $this->paginate(new RequestSpec('GET', self::ISSUERS, query: $params, options: $options));

        return $page;
    }

    /**
     * Retrieves an issuer.
     *
     * @param RequestOptions $options
     *
     * @return Issuer
     *
     * @throws FlashpiException
     */
    public function get(string $issuerId, array $options = []): array
    {
        /** @var Issuer $issuer */
        $issuer = $this->send(new RequestSpec('GET', Path::of(self::ISSUER, $issuerId), options: $options));

        return $issuer;
    }

    /**
     * Updates an issuer's name, tax profile, branding or defaults (only the fields sent).
     *
     * @param UpdateIssuer $body
     * @param RequestOptions $options
     *
     * @return Issuer
     *
     * @throws FlashpiException
     */
    public function update(string $issuerId, array $body, array $options = []): array
    {
        /** @var Issuer $issuer */
        $issuer = $this->send(new RequestSpec('PATCH', Path::of(self::ISSUER, $issuerId), body: $body, options: $options));

        return $issuer;
    }

    /**
     * Deletes an issuer that has no documents and no certificates, with its establishments,
     * emission points and issuer-scoped API keys. Otherwise a ConflictException with the error
     * code `resource_in_use`: certificates cannot be deleted, so an issuer that ever had a .p12
     * (one from an issuer onboarding too) stays.
     *
     * @param RequestOptions $options
     *
     * @throws ConflictException
     * @throws FlashpiException
     */
    public function delete(string $issuerId, array $options = []): void
    {
        $this->send(new RequestSpec('DELETE', Path::of(self::ISSUER, $issuerId), responseType: 'none', options: $options));
    }

    /**
     * The same methods, returning an ApiResponse with the HTTP response and the request id.
     */
    public function withResponse(): IssuersWithResponse
    {
        return $this->view(IssuersWithResponse::class);
    }
}
