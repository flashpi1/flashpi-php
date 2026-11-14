<?php

declare(strict_types=1);

namespace Flashpi\Internal;

use Flashpi\ApiResponse;
use Flashpi\Exception\FlashpiException;

/**
 * The withResponse() view of a resource: the same methods, returning an ApiResponse with the
 * data, the HTTP response and the request id. A resource's view only declares its methods with
 * `@method` tags; this class runs them on the resource and keeps the response they got.
 *
 * ```php
 * /**
 *  * @method ApiResponse<Document> get(string $documentId, RequestOptions $options = [])
 *  *\/
 * final class DocumentsWithResponse extends ResponseView {}
 * ```
 *
 * @internal
 */
abstract class ResponseView
{
    /**
     * @internal Built by Resource::view().
     */
    final public function __construct(private readonly Resource $resource) {}

    /**
     * @param array<array-key, mixed> $arguments
     *
     * @return ApiResponse<mixed>
     *
     * @throws FlashpiException
     * @throws \BadMethodCallException When the resource has no such public method.
     */
    public function __call(string $name, #[\SensitiveParameter] array $arguments): ApiResponse
    {
        if ($name === 'withResponse' || $name === 'recordingInto' || !\is_callable([$this->resource, $name])) {
            throw new \BadMethodCallException(\sprintf('%s has no method %s()', $this->resource::class, $name));
        }
        // A recorder of this call's own, on a copy of the resource: the response is this call's
        // even when calls on the same client interleave (fibers).
        $recorder = new Recorder();
        $data = $this->resource->recordingInto($recorder)->{$name}(...$arguments);
        if ($recorder->response === null) {
            throw new FlashpiException('The call sent no request, so there is no response to return');
        }

        return new ApiResponse($data, $recorder->response->response, $recorder->response->requestId);
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['resource' => $this->resource::class];
    }
}
