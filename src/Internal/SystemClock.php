<?php

declare(strict_types=1);

namespace Flashpi\Internal;

/**
 * @internal
 */
final class SystemClock implements Clock
{
    public function now(): float
    {
        return hrtime(true) / 1e9;
    }

    public function sleep(float $seconds): void
    {
        if ($seconds > 0) {
            usleep((int) round($seconds * 1e6));
        }
    }

    public function random(): float
    {
        return mt_rand(0, mt_getrandmax() - 1) / mt_getrandmax();
    }
}
