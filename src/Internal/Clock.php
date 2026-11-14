<?php

declare(strict_types=1);

namespace Flashpi\Internal;

/**
 * Time and chance for the retries: the tests replace them with a fake clock and no jitter.
 *
 * @internal
 */
interface Clock
{
    /** Seconds on a monotonic clock. */
    public function now(): float;

    public function sleep(float $seconds): void;

    /** A random number from 0 (included) to 1 (excluded). */
    public function random(): float;
}
