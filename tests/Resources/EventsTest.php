<?php

declare(strict_types=1);

namespace Flashpi\Tests\Resources;

use Flashpi\ApiResponse;
use Flashpi\Page;
use Flashpi\Tests\Support\Replies;
use Flashpi\Tests\Support\TestCase;

/**
 * `$flashpi->events` (Python: tests/test_resources.py).
 */
final class EventsTest extends TestCase
{
    private const DOCUMENT = 'doc_1';

    private const EVENT = 'evt_1';

    public function testListsEventsWithTheirFilters(): void
    {
        $api = self::api([Replies::page([])]);
        $page = $api->client->events->list(['documentId' => self::DOCUMENT]);
        self::assertSame([], $page->data);
        self::assertSentOnly($api, 'GET', '/v1/events', ['documentId' => self::DOCUMENT]);
    }

    public function testGetsAnEvent(): void
    {
        $api = self::api([Replies::json(['id' => self::EVENT])]);
        self::assertSame(['id' => self::EVENT], $api->client->events->get(self::EVENT));
        self::assertSentOnly($api, 'GET', '/v1/events/' . self::EVENT);
    }

    public function testWalksEveryEventAcrossPagesWithTheSameFilters(): void
    {
        $api = self::api([Replies::page([['id' => 'evt_1']], 'c1'), Replies::page([['id' => 'evt_2']])]);
        $ids = [];
        foreach ($api->client->events->list(['documentId' => self::DOCUMENT, 'limit' => 1]) as $event) {
            $ids[] = $event['id'];
        }
        self::assertSame(['evt_1', 'evt_2'], $ids);
        self::assertSame([['documentId' => self::DOCUMENT, 'limit' => '1'], ['documentId' => self::DOCUMENT, 'limit' => '1', 'cursor' => 'c1']], self::queries($api));
    }

    public function testReturnsTheResponseWithWithResponse(): void
    {
        $api = self::api([
            Replies::json(['id' => self::EVENT], 200, ['X-Request-Id' => 'req_event']),
            Replies::page([['id' => self::EVENT]]),
        ]);
        $result = $api->client->events->withResponse()->get(self::EVENT);
        self::assertSame(['id' => self::EVENT], $result->data);
        self::assertSame('req_event', $result->requestId);
        self::assertSame(200, $result->response->getStatusCode());
        $list = $api->client->events->withResponse()->list();
        self::assertInstanceOf(ApiResponse::class, $list);
        self::assertInstanceOf(Page::class, $list->data);
        self::assertSame([['id' => self::EVENT]], $list->data->data);
        self::assertSame('req_123', $list->requestId);
    }
}
