<?php

declare(strict_types=1);

namespace Flashpi\Resources;

use Flashpi\ApiResponse;
use Flashpi\Internal\ResponseView;
use Flashpi\Page;
use Flashpi\Types\ApiTypes;
use Flashpi\Types\SdkTypes;

/**
 * `$flashpi->webhookEndpoints->deliveries->withResponse()`: the same methods, returning an
 * ApiResponse with the HTTP response and the request id.
 *
 * @phpstan-import-type RequestOptions from SdkTypes
 * @phpstan-import-type WebhookDelivery from ApiTypes
 * @phpstan-import-type WebhookDeliveryDetail from ApiTypes
 * @phpstan-import-type WebhookDeliveryListParams from SdkTypes
 *
 * @method ApiResponse<Page<WebhookDelivery>> list(string $endpointId, WebhookDeliveryListParams|null $params = null, RequestOptions $options = [])
 * @method ApiResponse<WebhookDeliveryDetail> get(string $endpointId, string $deliveryId, RequestOptions $options = [])
 * @method ApiResponse<WebhookDeliveryDetail> replay(string $endpointId, string $deliveryId, RequestOptions $options = [])
 */
final class WebhookDeliveriesWithResponse extends ResponseView {}
