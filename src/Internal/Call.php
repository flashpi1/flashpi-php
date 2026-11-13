<?php

declare(strict_types=1);

namespace Flashpi\Internal;

/**
 * What every attempt of one call shares, resolved before the first one is sent.
 *
 * @phpstan-import-type CheckedOptions from Options
 *
 * @internal
 */
final class Call
{
    /**
     * @param CheckedOptions $options
     * @param float $timeout Seconds each attempt may take; also the time budget of the wait for
     *                       an earlier attempt still in progress.
     * @param string|null $idempotencyKey The Idempotency-Key of a POST.
     * @param string|null $content The JSON body, serialized once: every attempt sends the same
     *                             bytes, even when the caller changes the array meanwhile.
     */
    public function __construct(
        public readonly array $options,
        public readonly int $maxRetries,
        public readonly float $timeout,
        public readonly string $url,
        public readonly ?string $idempotencyKey,
        public readonly ?string $content,
    ) {}
}
