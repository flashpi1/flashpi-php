<?php

declare(strict_types=1);

namespace Flashpi\Resources;

use Flashpi\Exception\FlashpiException;
use Flashpi\Internal\Engine;
use Flashpi\Internal\Path;
use Flashpi\Internal\RequestSpec;
use Flashpi\Internal\Resource;
use Flashpi\Page;
use Flashpi\Types\ApiTypes;
use Flashpi\Types\SdkTypes;

/**
 * Establishments (the 3-digit series codes such as `001`) of an issuer:
 * `$flashpi->issuers->establishments`.
 *
 * @phpstan-import-type CreateEstablishment from ApiTypes
 * @phpstan-import-type Establishment from ApiTypes
 * @phpstan-import-type EstablishmentListParams from SdkTypes
 * @phpstan-import-type RequestOptions from SdkTypes
 * @phpstan-import-type UpdateEstablishment from ApiTypes
 */
final class Establishments extends Resource
{
    private const ESTABLISHMENTS = '/v1/issuers/{}/establishments';

    private const ESTABLISHMENT = '/v1/issuers/{}/establishments/{}';

    /** Emission points of an establishment. */
    public readonly EmissionPoints $emissionPoints;

    /**
     * @internal Built by the client.
     */
    public function __construct(Engine $engine)
    {
        parent::__construct($engine);
        $this->emissionPoints = new EmissionPoints($engine);
    }

    /**
     * Creates an establishment.
     *
     * @param CreateEstablishment $body
     * @param RequestOptions $options
     *
     * @return Establishment
     *
     * @throws FlashpiException
     */
    public function create(string $issuerId, array $body, array $options = []): array
    {
        /** @var Establishment $establishment */
        $establishment = $this->send(new RequestSpec('POST', Path::of(self::ESTABLISHMENTS, $issuerId), body: $body, options: $options));

        return $establishment;
    }

    /**
     * Lists the establishments of an issuer, by code. Iterate the page with foreach to walk every
     * following page too.
     *
     * @param EstablishmentListParams|null $params
     * @param RequestOptions $options
     *
     * @return Page<Establishment>
     *
     * @throws FlashpiException
     */
    public function list(string $issuerId, ?array $params = null, array $options = []): Page
    {
        /** @var Page<Establishment> $page */
        $page = $this->paginate(new RequestSpec('GET', Path::of(self::ESTABLISHMENTS, $issuerId), query: $params, options: $options));

        return $page;
    }

    /**
     * Retrieves an establishment.
     *
     * @param RequestOptions $options
     *
     * @return Establishment
     *
     * @throws FlashpiException
     */
    public function get(string $issuerId, string $establishmentId, array $options = []): array
    {
        /** @var Establishment $establishment */
        $establishment = $this->send(new RequestSpec('GET', Path::of(self::ESTABLISHMENT, $issuerId, $establishmentId), options: $options));

        return $establishment;
    }

    /**
     * Updates an establishment (only the fields sent).
     *
     * @param UpdateEstablishment $body
     * @param RequestOptions $options
     *
     * @return Establishment
     *
     * @throws FlashpiException
     */
    public function update(string $issuerId, string $establishmentId, array $body, array $options = []): array
    {
        /** @var Establishment $establishment */
        $establishment = $this->send(new RequestSpec('PATCH', Path::of(self::ESTABLISHMENT, $issuerId, $establishmentId), body: $body, options: $options));

        return $establishment;
    }

    /**
     * Deletes an establishment.
     *
     * @param RequestOptions $options
     *
     * @throws FlashpiException
     */
    public function delete(string $issuerId, string $establishmentId, array $options = []): void
    {
        $this->send(new RequestSpec('DELETE', Path::of(self::ESTABLISHMENT, $issuerId, $establishmentId), responseType: 'none', options: $options));
    }

    /**
     * The same methods, returning an ApiResponse with the HTTP response and the request id.
     */
    public function withResponse(): EstablishmentsWithResponse
    {
        return $this->view(EstablishmentsWithResponse::class);
    }
}
