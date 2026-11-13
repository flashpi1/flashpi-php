<?php

declare(strict_types=1);

namespace Flashpi\Resources;

use Flashpi\ApiResponse;
use Flashpi\Internal\ResponseView;
use Flashpi\Page;
use Flashpi\Types\ApiTypes;
use Flashpi\Types\SdkTypes;

/**
 * `$flashpi->webhookEndpoints->withResponse()`: the same methods, returning an ApiResponse with
 * the HTTP response and the request id.
 *
 * @phpstan-import-type CreateWebhookEndpoint from ApiTypes
 * @phpstan-import-type RequestOptions from SdkTypes
 * @phpstan-import-type RotateWebhookEndpointSecret from ApiTypes
 * @phpstan-import-type UpdateWebhookEndpoint from ApiTypes
 * @phpstan-import-type WebhookEndpoint from ApiTypes
 * @phpstan-import-type WebhookEndpointListParams from SdkTypes
 * @phpstan-import-type WebhookEndpointSecret from ApiTypes
 *
 * @method ApiResponse<WebhookEndpoint> create(CreateWebhookEndpoint $body, RequestOptions $options = [])
 * @method ApiResponse<Page<WebhookEndpoint>> list(WebhookEndpointListParams|null $params = null, RequestOptions $options = [])
 * @method ApiResponse<WebhookEndpoint> get(string $endpointId, RequestOptions $options = [])
 * @method ApiResponse<WebhookEndpoint> update(string $endpointId, UpdateWebhookEndpoint $body, RequestOptions $options = [])
 * @method ApiResponse<null> delete(string $endpointId, RequestOptions $options = [])
 * @method ApiResponse<WebhookEndpointSecret> getSecret(string $endpointId, RequestOptions $options = [])
 * @method ApiResponse<WebhookEndpoint> rotateSecret(string $endpointId, RotateWebhookEndpointSecret|null $body = null, RequestOptions $options = [])
 * @method ApiResponse<WebhookEndpoint> verify(string $endpointId, RequestOptions $options = [])
 */
final class WebhookEndpointsWithResponse extends ResponseView {}
