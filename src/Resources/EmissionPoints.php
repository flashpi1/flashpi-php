<?php

declare(strict_types=1);

namespace Flashpi\Resources;

use Flashpi\Exception\FlashpiException;
use Flashpi\Internal\Path;
use Flashpi\Internal\RequestSpec;
use Flashpi\Internal\Resource;
use Flashpi\Page;
use Flashpi\Types\ApiTypes;
use Flashpi\Types\SdkTypes;

/**
 * Emission points (the second 3-digit series code) of an establishment:
 * `$flashpi->issuers->establishments->emissionPoints`.
 *
 * @phpstan-import-type CreateEmissionPoint from ApiTypes
 * @phpstan-import-type EmissionPoint from ApiTypes
 * @phpstan-import-type EmissionPointListParams from SdkTypes
 * @phpstan-import-type RequestOptions from SdkTypes
 */
final class EmissionPoints extends Resource
{
    private const EMISSION_POINTS = '/v1/issuers/{}/establishments/{}/emission-points';

    private const EMISSION_POINT = '/v1/issuers/{}/establishments/{}/emission-points/{}';

    /**
     * Creates an emission point.
     *
     * @param CreateEmissionPoint $body
     * @param RequestOptions $options
     *
     * @return EmissionPoint
     *
     * @throws FlashpiException
     */
    public function create(string $issuerId, string $establishmentId, array $body, array $options = []): array
    {
        /** @var EmissionPoint $point */
        $point = $this->send(new RequestSpec('POST', Path::of(self::EMISSION_POINTS, $issuerId, $establishmentId), body: $body, options: $options));

        return $point;
    }

    /**
     * Lists the emission points of an establishment, by code. Iterate the page with foreach to
     * walk every following page too.
     *
     * @param EmissionPointListParams|null $params
     * @param RequestOptions $options
     *
     * @return Page<EmissionPoint>
     *
     * @throws FlashpiException
     */
    public function list(string $issuerId, string $establishmentId, ?array $params = null, array $options = []): Page
    {
        /** @var Page<EmissionPoint> $page */
        $page = $this->paginate(new RequestSpec('GET', Path::of(self::EMISSION_POINTS, $issuerId, $establishmentId), query: $params, options: $options));

        return $page;
    }

    /**
     * Retrieves an emission point.
     *
     * @param RequestOptions $options
     *
     * @return EmissionPoint
     *
     * @throws FlashpiException
     */
    public function get(string $issuerId, string $establishmentId, string $emissionPointId, array $options = []): array
    {
        /** @var EmissionPoint $point */
        $point = $this->send(new RequestSpec('GET', Path::of(self::EMISSION_POINT, $issuerId, $establishmentId, $emissionPointId), options: $options));

        return $point;
    }

    /**
     * Deletes an emission point.
     *
     * @param RequestOptions $options
     *
     * @throws FlashpiException
     */
    public function delete(string $issuerId, string $establishmentId, string $emissionPointId, array $options = []): void
    {
        $this->send(new RequestSpec('DELETE', Path::of(self::EMISSION_POINT, $issuerId, $establishmentId, $emissionPointId), responseType: 'none', options: $options));
    }

    /**
     * The same methods, returning an ApiResponse with the HTTP response and the request id.
     */
    public function withResponse(): EmissionPointsWithResponse
    {
        return $this->view(EmissionPointsWithResponse::class);
    }
}
