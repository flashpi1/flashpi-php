<?php

declare(strict_types=1);

namespace Flashpi;

use Psr\Http\Message\ResponseInterface;

/**
 * The data of a call together with the HTTP response it came from, as the withResponse() view of
 * every resource returns it: `$flashpi->documents->withResponse()->get($id)`.
 *
 * @template-covariant T
 */
final class ApiResponse
{
    /**
     * @param T $data What the method returns.
     * @param ResponseInterface $response The HTTP response (status, headers). Its body was already
     *                                    read into `data`; reading it again gives the same bytes.
     * @param string|null $requestId Flashpi's id of the request (`x-request-id`): share it with
     *                               support.
     *
     * @internal Built by the SDK.
     */
    public function __construct(
        public readonly mixed $data,
        public readonly ResponseInterface $response,
        public readonly ?string $requestId,
    ) {}
}
