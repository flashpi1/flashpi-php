<?php

declare(strict_types=1);

namespace Flashpi\Tests\Support;

use Flashpi\Internal\Clock;

/**
 * A fake clock for the retries' waits: sleeping advances it at once, and there is no jitter, so
 * every backoff is the full delay.
 */
final class FakeClock implements Clock
{
    public float $now = 1000.0;

    public float $started = 1000.0;

    /** @var list<float> */
    public array $sleeps = [];

    public float $jitter = 0.0;

    public function now(): float
    {
        return $this->now;
    }

    public function sleep(float $seconds): void
    {
        $this->sleeps[] = $seconds;
        $this->advance($seconds);
    }

    public function random(): float
    {
        return $this->jitter;
    }

    public function advance(float $seconds): void
    {
        $this->now += $seconds;
    }

    public function elapsed(): float
    {
        return round($this->now - $this->started, 3);
    }
}
