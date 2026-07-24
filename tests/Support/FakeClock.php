<?php

declare(strict_types=1);

namespace Tests\Support;

use Silviooosilva\CacheerPhp\Contracts\Clock;

final class FakeClock implements Clock
{
    public function __construct(private float $timestamp = 1_700_000_000.0)
    {
    }

    public function now(): int
    {
        return (int) floor($this->timestamp);
    }

    public function nowFloat(): float
    {
        return $this->timestamp;
    }

    public function sleep(int $microseconds): void
    {
        $this->timestamp += $microseconds / 1_000_000;
    }

    public function advance(float $seconds): self
    {
        $this->timestamp += $seconds;

        return $this;
    }
}
