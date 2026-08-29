<?php

declare(strict_types=1);

namespace Silviooosilva\CacheerPhp\Contracts;

use Silviooosilva\CacheerPhp\Kernel\Key;
use Silviooosilva\CacheerPhp\Kernel\Ttl;

/**
 * Lost-update-free counters and optimistic writes.
 *
 * The atomicity is the backend's: ArrayStore holds within one process, FileStore
 * serialises on a per-key lock, DatabaseStore row-locks, RedisStore uses server
 * atomics.
 */
interface AtomicStore
{
    /**
     * @param Key $key
     * @param int $amount
     * @param ?int $initial
     * @param ?Ttl $ttl
     * @return int
     */
    public function increment(Key $key, int $amount = 1, ?int $initial = null, ?Ttl $ttl = null): int;

    /**
     * @param Key $key
     * @param mixed $expected
     * @param mixed $value
     * @param ?Ttl $ttl
     * @return bool
     */
    public function compareAndSwap(Key $key, mixed $expected, mixed $value, ?Ttl $ttl = null): bool;
}
