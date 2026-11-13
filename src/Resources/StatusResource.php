<?php

declare(strict_types=1);

namespace Flashpi\Resources;

use Flashpi\Exception\FlashpiException;
use Flashpi\Internal\RequestSpec;
use Flashpi\Internal\Resource;
use Flashpi\Types\ApiTypes;
use Flashpi\Types\SdkTypes;

/**
 * The API's own status (`$flashpi->status`). Node exports it as `StatusResource` too: the name
 * `Status` is the type of its answer.
 *
 * @phpstan-import-type Status from ApiTypes
 * @phpstan-import-type RequestOptions from SdkTypes
 */
final class StatusResource extends Resource
{
    /**
     * Whether Flashpi is operational.
     *
     * @param RequestOptions $options
     *
     * @return Status
     *
     * @throws FlashpiException
     */
    public function get(array $options = []): array
    {
        /** @var Status $status */
        $status = $this->send(new RequestSpec('GET', '/v1/status', options: $options));

        return $status;
    }

    /**
     * The same methods, returning an ApiResponse with the HTTP response and the request id.
     */
    public function withResponse(): StatusResourceWithResponse
    {
        return $this->view(StatusResourceWithResponse::class);
    }
}
