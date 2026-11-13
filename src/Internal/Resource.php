<?php

declare(strict_types=1);

namespace Flashpi\Internal;

use Flashpi\ApiResponse;
use Flashpi\Exception\FlashpiException;
use Flashpi\Page;

/**
 * Base of every resource: a resource only declares its operations as RequestSpecs.
 *
 * ```php
 * final class Documents extends Resource
 * {
 *     public function get(string $documentId, array $options = []): array
 *     {
 *         /** @var Document $document *\/
 *         $document = $this->send(new RequestSpec('GET', Path::of('/v1/documents/{}', $documentId), options: $options));
 *
 *         return $document;
 *     }
 *
 *     public function list(?array $params = null, array $options = []): Page
 *     {
 *         /** @var Page<Document> $page *\/
 *         $page = $this->paginate(new RequestSpec('GET', '/v1/documents', query: $params, options: $options));
 *
 *         return $page;
 *     }
 *
 *     public function withResponse(): DocumentsWithResponse
 *     {
 *         return $this->view(DocumentsWithResponse::class);
 *     }
 * }
 * ```
 *
 * @internal
 */
abstract class Resource
{
    /**
     * A resource with nested resources builds them in its own constructor, after calling this one:
     *
     * ```php
     * public function __construct(Engine $engine)
     * {
     *     parent::__construct($engine);
     *     $this->establishments = new Establishments($engine);
     * }
     * ```
     *
     * @internal Built by the client.
     */
    public function __construct(protected readonly Engine $engine) {}

    /**
     * Sends the request (with its retries) and returns its data.
     *
     * @throws FlashpiException
     */
    final protected function send(RequestSpec $spec): mixed
    {
        return $this->sendForResponse($spec)->data;
    }

    /**
     * Sends the request (with its retries) and returns its data with the response, for an
     * operation that reads a header too.
     *
     * @return ApiResponse<mixed>
     *
     * @throws FlashpiException
     */
    final protected function sendForResponse(RequestSpec $spec): ApiResponse
    {
        $result = $this->engine->request($spec);
        $this->engine->record($result);

        return $result;
    }

    /**
     * The first page of a list; iterating it walks every page.
     *
     * @return Page<mixed>
     *
     * @throws FlashpiException
     */
    final protected function paginate(RequestSpec $spec): Page
    {
        $first = $this->fetchPage($spec, $spec);
        $this->engine->record($first);

        return $first->data;
    }

    /**
     * The withResponse() view of this resource.
     *
     * @template V of ResponseView
     *
     * @param class-string<V> $view
     *
     * @return V
     */
    final protected function view(string $view): ResponseView
    {
        return new $view($this, $this->engine);
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [];
    }

    /**
     * @return ApiResponse<Page<mixed>>
     */
    private function fetchPage(RequestSpec $spec, RequestSpec $first): ApiResponse
    {
        $result = $this->engine->request($spec);
        $page = new Page(
            $result->data,
            fn(string $cursor): Page => $this->fetchPage($first->withCursor($cursor), $first)->data,
        );

        return new ApiResponse($page, $result->response, $result->requestId);
    }
}
