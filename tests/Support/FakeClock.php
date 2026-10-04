<?php

declare(strict_types=1);

namespace Tests\Support;

use Silviooosilva\CacheerPhp\Contracts\Clock;

final class FakeClock implements Clock
{
    /**
     * Runs on every sleep(), so a test can act as another worker while code
     * under test is waiting (e.g. for a lock).
     *
     * @var ?\Closure(): void
     */
    public ?\Closure $onSleep = null;

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

        if ($this->onSleep !== null) {
            ($this->onSleep)();
        }
    }

    public function advance(float $seconds): self
    {
        $this->timestamp += $seconds;

        return $this;
    }
}
