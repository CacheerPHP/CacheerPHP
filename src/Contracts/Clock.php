<?php

declare(strict_types=1);

namespace Silviooosilva\CacheerPhp\Contracts;

/**
 * Internal time source used by cache expiration and lock polling.
 *
 * Applications normally use SystemClock. Tests may inject a deterministic
 * implementation through the Cacheer "clock" option.
 */
interface Clock
{
    /**
     * @return int
     */
    public function now(): int;

    /**
     * @return float
     */
    public function nowFloat(): float;

    /**
     * @param int $microseconds
     */
    public function sleep(int $microseconds): void;
}
