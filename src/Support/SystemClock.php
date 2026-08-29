<?php

declare(strict_types=1);

namespace Silviooosilva\CacheerPhp\Support;

use Silviooosilva\CacheerPhp\Contracts\Clock;

/**
 * The real clock. Tests inject a fake one instead, which is why no store calls
 * time() directly.
 */
final class SystemClock implements Clock
{
    /**
     * @return int
     */
    public function now(): int
    {
        return time();
    }

    /**
     * @return float
     */
    public function nowFloat(): float
    {
        return microtime(true);
    }

    /**
     * @param int $microseconds
     */
    public function sleep(int $microseconds): void
    {
        usleep($microseconds);
    }
}
