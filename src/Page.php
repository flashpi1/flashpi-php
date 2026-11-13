<?php

declare(strict_types=1);

namespace Flashpi;

use Flashpi\Exception\FlashpiException;

/**
 * One page of a list, newest first (establishments and emission points: by code).
 *
 * Iterate it with foreach to walk this page and every following one, fetching them as needed:
 *
 * ```php
 * foreach ($flashpi->documents->list(['status' => 'authorized']) as $document) {
 *     echo $document['accessKey'], "\n";
 * }
 * ```
 *
 * Or move one page at a time with `data`, hasNextPage() and nextPage().
 *
 * @template-covariant T
 *
 * @implements \IteratorAggregate<int, T>
 */
final class Page implements \IteratorAggregate
{
    /**
     * The items of this page.
     *
     * @var list<T>
     */
    public readonly array $data;

    /** Whether more items follow this page. */
    public readonly bool $hasMore;

    /** The cursor of the next page, or null on the last one. */
    public readonly ?string $nextCursor;

    /** @var \Closure(string): Page<T> */
    private readonly \Closure $fetchPage;

    /**
     * @param mixed $body The answer of a list operation: `data` and `pagination`.
     * @param \Closure(string): Page<T> $fetchPage Fetches the page after a cursor, with the same
     *                                             filters and options.
     *
     * @throws FlashpiException When the answer is not a list.
     *
     * @internal Built by the SDK.
     */
    public function __construct(mixed $body, \Closure $fetchPage)
    {
        $data = \is_array($body) ? $body['data'] ?? null : null;
        $pagination = \is_array($body) ? $body['pagination'] ?? null : null;
        if (!\is_array($data) || !array_is_list($data) || !\is_array($pagination)) {
            throw new FlashpiException('The API answered a list without data and pagination');
        }
        $cursor = $pagination['nextCursor'] ?? null;
        /** @var list<T> $data */
        $this->data = $data;
        $this->hasMore = ($pagination['hasMore'] ?? null) === true;
        $this->nextCursor = \is_string($cursor) ? $cursor : null;
        $this->fetchPage = $fetchPage;
    }

    /**
     * Whether nextPage() will return a page.
     */
    public function hasNextPage(): bool
    {
        return $this->hasMore && $this->nextCursor !== null;
    }

    /**
     * The next page (same filters and options), or null after the last page.
     *
     * @return Page<T>|null
     *
     * @throws FlashpiException
     */
    public function nextPage(): ?self
    {
        if (!$this->hasMore || $this->nextCursor === null) {
            return null;
        }

        return ($this->fetchPage)($this->nextCursor);
    }

    /**
     * Every item of this page and of the following ones, fetched as the loop reaches them.
     *
     * @return \Generator<int, T, mixed, void>
     *
     * @throws FlashpiException
     */
    public function getIterator(): \Generator
    {
        $page = $this;
        while ($page !== null) {
            foreach ($page->data as $item) {
                yield $item;
            }
            $page = $page->nextPage();
        }
    }

    /**
     * @return array{data: list<T>, hasMore: bool, nextCursor: string|null}
     */
    public function __debugInfo(): array
    {
        return ['data' => $this->data, 'hasMore' => $this->hasMore, 'nextCursor' => $this->nextCursor];
    }
}
