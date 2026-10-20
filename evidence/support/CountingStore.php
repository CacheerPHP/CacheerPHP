<?php

declare(strict_types=1);

namespace Evidence;

use Silviooosilva\CacheerPhp\Contracts\Store;
use Silviooosilva\CacheerPhp\Kernel\CacheEntry;
use Silviooosilva\CacheerPhp\Kernel\Key;
use Silviooosilva\CacheerPhp\Kernel\Ttl;

/**
 * Counts the calls that reach a store. Wrapped around a tiered cache's L2, it
 * shows how much work L1 absorbs; around a plain store, it gives the baseline.
 * It exposes only the four core methods, which is all the benchmark uses.
 */
final class CountingStore implements Store
{
    /** @var array{get: int, set: int, delete: int, clear: int} */
    public array $calls = ['get' => 0, 'set' => 0, 'delete' => 0, 'clear' => 0];

    public function __construct(private readonly Store $inner)
    {
    }

    public function get(Key $key): CacheEntry
    {
        $this->calls['get']++;

        return $this->inner->get($key);
    }

    public function set(Key $key, mixed $value, Ttl $ttl): void
    {
        $this->calls['set']++;
        $this->inner->set($key, $value, $ttl);
    }

    public function delete(Key $key): bool
    {
        $this->calls['delete']++;

        return $this->inner->delete($key);
    }

    public function clear(): void
    {
        $this->calls['clear']++;
        $this->inner->clear();
    }

    public function total(): int
    {
        return array_sum($this->calls);
    }
}
