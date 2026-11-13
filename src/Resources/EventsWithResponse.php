<?php

declare(strict_types=1);

namespace Flashpi\Resources;

use Flashpi\ApiResponse;
use Flashpi\Internal\ResponseView;
use Flashpi\Page;
use Flashpi\Types\ApiTypes;
use Flashpi\Types\SdkTypes;

/**
 * `$flashpi->events->withResponse()`: the same methods, returning an ApiResponse with the HTTP
 * response and the request id.
 *
 * @phpstan-import-type Event from ApiTypes
 * @phpstan-import-type EventListParams from SdkTypes
 * @phpstan-import-type RequestOptions from SdkTypes
 *
 * @method ApiResponse<Page<Event>> list(EventListParams|null $params = null, RequestOptions $options = [])
 * @method ApiResponse<Event> get(string $eventId, RequestOptions $options = [])
 */
final class EventsWithResponse extends ResponseView {}
