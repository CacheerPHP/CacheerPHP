<?php

declare(strict_types=1);

namespace Silviooosilva\CacheerPhp\Contracts;

use Silviooosilva\CacheerPhp\Kernel\CacheEntry;
use Silviooosilva\CacheerPhp\Kernel\Key;
use Silviooosilva\CacheerPhp\Kernel\Ttl;

/**
 * The universal v6 cache-store contract.
 *
 * Optional behavior belongs to capability interfaces, keeping custom stores
 * small and honest about the guarantees they provide.
 */
interface Store
{
    /**
     * @param Key $key
     * @return CacheEntry
     */
    public function get(Key $key): CacheEntry;

    /**
     * @param Key $key
     * @param mixed $value
     * @param Ttl $ttl
     */
    public function set(Key $key, mixed $value, Ttl $ttl): void;

    /**
     * @param Key $key
     * @return bool
     */
    public function delete(Key $key): bool;

    public function clear(): void;
}
