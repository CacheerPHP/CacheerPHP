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
    public function now(): int;

    public function nowFloat(): float;

    public function sleep(int $microseconds): void;
}
