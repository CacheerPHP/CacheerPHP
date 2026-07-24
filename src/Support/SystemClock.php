<?php

declare(strict_types=1);

namespace Silviooosilva\CacheerPhp\Support;

use Silviooosilva\CacheerPhp\Contracts\Clock;

final class SystemClock implements Clock
{
    public function now(): int
    {
        return time();
    }

    public function nowFloat(): float
    {
        return microtime(true);
    }

    public function sleep(int $microseconds): void
    {
        usleep($microseconds);
    }
}
