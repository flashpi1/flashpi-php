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
 * The URLs Flashpi sends events to (`$flashpi->webhookEndpoints`), with the deliveries of those
 * events. Needs an account-scoped API key with read_write access. To check the signature of a
 * webhook you receive, use `$flashpi->webhooks` instead.
 *
 * @phpstan-import-type CreateWebhookEndpoint from ApiTypes
 * @phpstan-import-type RequestOptions from SdkTypes
 * @phpstan-import-type RotateWebhookEndpointSecret from ApiTypes
 * @phpstan-import-type UpdateWebhookEndpoint from ApiTypes
 * @phpstan-import-type WebhookEndpoint from ApiTypes
 * @phpstan-import-type WebhookEndpointListParams from SdkTypes
 * @phpstan-import-type WebhookEndpointSecret from ApiTypes
 */
final class WebhookEndpoints extends Resource
{
    private const ENDPOINTS = '/v1/webhook-endpoints';

    private const ENDPOINT = '/v1/webhook-endpoints/{}';

    /** The deliveries of an endpoint: attempts, answers and manual replays. */
    public readonly WebhookDeliveries $deliveries;

    /**
     * @internal Built by the client.
     */
    public function __construct(Engine $engine)
    {
        parent::__construct($engine);
        $this->deliveries = new WebhookDeliveries($engine);
    }

    /**
     * Creates an endpoint. It stays `pending_verification` until it answers the activation
     * challenge; read its secret with getSecret() first, then call verify() if needed.
     *
     * @param CreateWebhookEndpoint $body
     * @param RequestOptions $options
     *
     * @return WebhookEndpoint
     *
     * @throws FlashpiException
     */
    public function create(array $body, array $options = []): array
    {
        /** @var WebhookEndpoint $endpoint */
        $endpoint = $this->send(new RequestSpec('POST', self::ENDPOINTS, body: $body, options: $options));

        return $endpoint;
    }

    /**
     * Lists the endpoints, newest first. Iterate the page with foreach to walk every following
     * page too.
     *
     * @param WebhookEndpointListParams|null $params
     * @param RequestOptions $options
     *
     * @return Page<WebhookEndpoint>
     *
     * @throws FlashpiException
     */
    public function list(?array $params = null, array $options = []): Page
    {
        /** @var Page<WebhookEndpoint> $page */
        $page = $this->paginate(new RequestSpec('GET', self::ENDPOINTS, query: $params, options: $options));

        return $page;
    }

    /**
     * Retrieves an endpoint.
     *
     * @param RequestOptions $options
     *
     * @return WebhookEndpoint
     *
     * @throws FlashpiException
     */
    public function get(string $endpointId, array $options = []): array
    {
        /** @var WebhookEndpoint $endpoint */
        $endpoint = $this->send(new RequestSpec('GET', Path::of(self::ENDPOINT, $endpointId), options: $options));

        return $endpoint;
    }

    /**
     * Updates the URL, events, payload mode, description or `disabled` (only the fields sent).
     *
     * @param UpdateWebhookEndpoint $body
     * @param RequestOptions $options
     *
     * @return WebhookEndpoint
     *
     * @throws FlashpiException
     */
    public function update(string $endpointId, array $body, array $options = []): array
    {
        /** @var WebhookEndpoint $endpoint */
        $endpoint = $this->send(new RequestSpec('PATCH', Path::of(self::ENDPOINT, $endpointId), body: $body, options: $options));

        return $endpoint;
    }

    /**
     * Deletes an endpoint.
     *
     * @param RequestOptions $options
     *
     * @throws FlashpiException
     */
    public function delete(string $endpointId, array $options = []): void
    {
        $this->send(new RequestSpec('DELETE', Path::of(self::ENDPOINT, $endpointId), responseType: 'none', options: $options));
    }

    /**
     * The signing secret (`whsec_...`) and, during a rotation, when the previous one expires.
     *
     * @param RequestOptions $options
     *
     * @return WebhookEndpointSecret
     *
     * @throws FlashpiException
     */
    public function getSecret(string $endpointId, array $options = []): array
    {
        /** @var WebhookEndpointSecret $secret */
        $secret = $this->send(new RequestSpec('GET', Path::of(self::ENDPOINT . '/secret', $endpointId), options: $options));

        return $secret;
    }

    /**
     * Replaces the signing secret. The previous one keeps signing for
     * `previousSecretExpiresInHours` (24 by default, 0 to drop it at once). Read the new secret
     * with getSecret().
     *
     * @param RotateWebhookEndpointSecret|null $body
     * @param RequestOptions $options
     *
     * @return WebhookEndpoint
     *
     * @throws FlashpiException
     */
    public function rotateSecret(string $endpointId, ?array $body = null, array $options = []): array
    {
        /** @var WebhookEndpoint $endpoint */
        $endpoint = $this->send(new RequestSpec('POST', Path::of(self::ENDPOINT . '/rotate-secret', $endpointId), body: $body, options: $options));

        return $endpoint;
    }

    /**
     * Sends the activation challenge (`webhook_endpoint.verification`) to the endpoint again.
     *
     * @param RequestOptions $options
     *
     * @return WebhookEndpoint
     *
     * @throws FlashpiException
     */
    public function verify(string $endpointId, array $options = []): array
    {
        /** @var WebhookEndpoint $endpoint */
        $endpoint = $this->send(new RequestSpec('POST', Path::of(self::ENDPOINT . '/verify', $endpointId), options: $options));

        return $endpoint;
    }

    /**
     * The same methods, returning an ApiResponse with the HTTP response and the request id.
     */
    public function withResponse(): WebhookEndpointsWithResponse
    {
        return $this->view(WebhookEndpointsWithResponse::class);
    }
}
