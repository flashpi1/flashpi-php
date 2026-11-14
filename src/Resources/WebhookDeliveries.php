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
 * Deliveries of events to one endpoint: `$flashpi->webhookEndpoints->deliveries`.
 *
 * @phpstan-import-type RequestOptions from SdkTypes
 * @phpstan-import-type WebhookDelivery from ApiTypes
 * @phpstan-import-type WebhookDeliveryDetail from ApiTypes
 * @phpstan-import-type WebhookDeliveryListParams from SdkTypes
 */
final class WebhookDeliveries extends Resource
{
    private const DELIVERIES = '/v1/webhook-endpoints/{}/deliveries';

    private const DELIVERY = '/v1/webhook-endpoints/{}/deliveries/{}';

    /**
     * Lists deliveries, newest first, optionally by status, event type or event. Iterate the page
     * with foreach to walk every following page too.
     *
     * @param WebhookDeliveryListParams|null $params
     * @param RequestOptions $options
     *
     * @return Page<WebhookDelivery>
     *
     * @throws FlashpiException
     */
    public function list(string $endpointId, ?array $params = null, array $options = []): Page
    {
        /** @var Page<WebhookDelivery> $page */
        $page = $this->paginate(new RequestSpec('GET', Path::of(self::DELIVERIES, $endpointId), query: $params, options: $options));

        return $page;
    }

    /**
     * Retrieves a delivery with its attempts.
     *
     * @param RequestOptions $options
     *
     * @return WebhookDeliveryDetail
     *
     * @throws FlashpiException
     */
    public function get(string $endpointId, string $deliveryId, array $options = []): array
    {
        /** @var WebhookDeliveryDetail $delivery */
        $delivery = $this->send(new RequestSpec('GET', Path::of(self::DELIVERY, $endpointId, $deliveryId), options: $options));

        return $delivery;
    }

    /**
     * Sends the delivery's event again, with the same `webhook-id`.
     *
     * @param RequestOptions $options
     *
     * @return WebhookDeliveryDetail
     *
     * @throws FlashpiException
     */
    public function replay(string $endpointId, string $deliveryId, array $options = []): array
    {
        /** @var WebhookDeliveryDetail $delivery */
        $delivery = $this->send(new RequestSpec('POST', Path::of(self::DELIVERY . '/replay', $endpointId, $deliveryId), options: $options));

        return $delivery;
    }

    /**
     * The same methods, returning an ApiResponse with the HTTP response and the request id.
     */
    public function withResponse(): WebhookDeliveriesWithResponse
    {
        return $this->view(WebhookDeliveriesWithResponse::class);
    }
}
