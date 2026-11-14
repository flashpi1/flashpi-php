<?php

declare(strict_types=1);

namespace Flashpi\Internal;

use Flashpi\ApiResponse;

/**
 * The response of one withResponse() call: the copy of the resource the call runs on keeps here
 * the last response it got. Each call has its own, so calls that interleave on one client (fibers,
 * coroutines) never read each other's.
 *
 * @internal
 */
final class Recorder
{
    /** @var ApiResponse<mixed>|null */
    public ?ApiResponse $response = null;

    /**
     * @param ApiResponse<mixed> $response
     */
    public function keep(ApiResponse $response): void
    {
        $this->response = $response;
    }
}
