<?php

declare(strict_types=1);

namespace Flashpi\Tests;

use Flashpi\Exception\FlashpiException;
use Flashpi\Page;
use Flashpi\Tests\Support\Replies;
use Flashpi\Tests\Support\TestCase;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;

/**
 * Lists and pagination (Python: tests/test_pagination.py).
 */
final class PaginationTest extends TestCase
{
    public function testReturnsTheFirstPage(): void
    {
        $api = self::api([Replies::page([['id' => 'a'], ['id' => 'b']], 'cursor-1')]);
        $first = $api->things->list(['status' => 'authorized', 'limit' => 2, 'issuerId' => null]);
        self::assertInstanceOf(Page::class, $first);
        self::assertSame([['id' => 'a'], ['id' => 'b']], $first->data);
        self::assertTrue($first->hasMore);
        self::assertSame('cursor-1', $first->nextCursor);
        self::assertTrue($first->hasNextPage());
        self::assertSame('status=authorized&limit=2', $api->request(0)->getUri()->getQuery());
        self::assertSame(['data' => [['id' => 'a'], ['id' => 'b']], 'hasMore' => true, 'nextCursor' => 'cursor-1'], $first->__debugInfo());
    }

    public function testFetchesTheNextPageWithTheSameFiltersAndOptions(): void
    {
        $api = self::api([Replies::page([['id' => 'a']], 'c1'), Replies::page([['id' => 'b']])]);
        $first = $api->things->list(['type' => 'invoice'], ['language' => 'es']);
        $second = $first->nextPage();
        self::assertNotNull($second);
        self::assertSame([['id' => 'b']], $second->data);
        self::assertFalse($second->hasNextPage());
        self::assertNull($second->nextPage());
        self::assertSame('type=invoice&cursor=c1', $api->request(1)->getUri()->getQuery());
        self::assertSame('es', $api->request(1)->getHeaderLine('accept-language'));
    }

    public function testIteratesEveryItemAcrossPages(): void
    {
        $api = self::api([Replies::page([['id' => 'a'], ['id' => 'b']], 'c1'), Replies::page([], 'c2'), Replies::page([['id' => 'c']])]);
        $items = iterator_to_array($api->things->list(['limit' => 2]));
        self::assertSame([['id' => 'a'], ['id' => 'b'], ['id' => 'c']], $items);
        self::assertSame(['limit=2', 'limit=2&cursor=c1', 'limit=2&cursor=c2'], array_map(
            static fn(RequestInterface $request): string => $request->getUri()->getQuery(),
            $api->sent,
        ));
    }

    public function testIteratesFromAPageTooAndTwice(): void
    {
        $api = self::api([Replies::page([['id' => 'a']], 'c1'), Replies::page([['id' => 'b']]), Replies::page([['id' => 'b']])]);
        $first = $api->things->list();
        $ids = [];
        foreach ($first as $item) {
            self::assertIsArray($item);
            $ids[] = $item['id'] ?? null;
        }
        self::assertSame(['a', 'b'], $ids);
        self::assertCount(2, iterator_to_array($first));
        self::assertCount(3, $api->sent);
    }

    public function testStartsFromAGivenCursor(): void
    {
        $api = self::api([Replies::page([], 'next'), Replies::page([])]);
        $page = $api->things->list(['cursor' => 'from-here', 'limit' => 100, 'archived' => false]);
        $page->nextPage();
        self::assertSame('cursor=from-here&limit=100&archived=false', $api->request(0)->getUri()->getQuery());
        self::assertSame('cursor=next&limit=100&archived=false', $api->request(1)->getUri()->getQuery());
    }

    public function testEncodesQueryValues(): void
    {
        $api = self::api([Replies::page([])]);
        $api->things->list(['q' => 'a b&c=d/é+~', 'flag' => true, 'ratio' => 1.5, 'since' => new class implements \Stringable {
            public function __toString(): string
            {
                return '2026-11-01';
            }
        }]);
        self::assertSame('q=a+b%26c%3Dd%2F%C3%A9%2B~&flag=true&ratio=1.5&since=2026-11-01', $api->request(0)->getUri()->getQuery());
    }

    public function testRefusesAQueryValueThatIsNotAScalar(): void
    {
        $api = self::api();
        self::throws(\InvalidArgumentException::class, static fn() => $api->things->list(['type' => ['invoice']]), '/^The query parameter type must be a string, a number or a boolean, got array$/');
        self::assertSame([], $api->sent);
    }

    public function testDoesNotFollowAPageThatSaysHasMoreWithoutACursor(): void
    {
        $api = self::api([Replies::json(['data' => [], 'pagination' => ['hasMore' => true, 'nextCursor' => null]])]);
        $first = $api->things->list();
        self::assertFalse($first->hasNextPage());
        self::assertNull($first->nextPage());
        self::assertSame([], iterator_to_array($first));
    }

    public function testExposesTheResponseOfTheFirstPage(): void
    {
        $api = self::api([Replies::page([])]);
        $result = $api->things->withResponse()->list();
        self::assertInstanceOf(Page::class, $result->data);
        self::assertSame([], $result->data->data);
        self::assertSame('req_123', $result->requestId);
    }

    public function testRefusesAListAnswerWithoutDataAndPagination(): void
    {
        $api = self::api([Replies::json(['data' => []]), Replies::json([1, 2]), Replies::json(['data' => ['a' => 1], 'pagination' => []]), new Response(204)]);
        for ($index = 0; $index < 4; $index++) {
            self::throws(FlashpiException::class, static fn() => $api->things->list(), '/^The API answered a list without data and pagination$/');
        }
    }

    public function testStopsWhenTheCallerBreaksOutOfTheLoop(): void
    {
        $api = self::api([Replies::page([['id' => 'a'], ['id' => 'b']], 'c1')]);
        foreach ($api->things->list() as $item) {
            self::assertSame(['id' => 'a'], $item);

            break;
        }
        self::assertCount(1, $api->sent);
    }
}
