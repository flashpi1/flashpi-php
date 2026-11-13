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
 * Events: what happened to documents, certificates and email sending, newest first
 * (`$flashpi->events`).
 *
 * @phpstan-import-type Event from ApiTypes
 * @phpstan-import-type EventListParams from SdkTypes
 * @phpstan-import-type RequestOptions from SdkTypes
 */
final class Events extends Resource
{
    /**
     * Lists events, optionally by type, issuer, document or certificate. Iterate the page with
     * foreach to walk every following page too.
     *
     * @param EventListParams|null $params
     * @param RequestOptions $options
     *
     * @return Page<Event>
     *
     * @throws FlashpiException
     */
    public function list(?array $params = null, array $options = []): Page
    {
        /** @var Page<Event> $page */
        $page = $this->paginate(new RequestSpec('GET', '/v1/events', query: $params, options: $options));

        return $page;
    }

    /**
     * Retrieves an event: the same object its webhook delivered.
     *
     * @param RequestOptions $options
     *
     * @return Event
     *
     * @throws FlashpiException
     */
    public function get(string $eventId, array $options = []): array
    {
        /** @var Event $event */
        $event = $this->send(new RequestSpec('GET', Path::of('/v1/events/{}', $eventId), options: $options));

        return $event;
    }

    /**
     * The same methods, returning an ApiResponse with the HTTP response and the request id.
     */
    public function withResponse(): EventsWithResponse
    {
        return $this->view(EventsWithResponse::class);
    }
}
