<?php

declare(strict_types=1);

namespace Tests\Support;

use RuntimeException;
use Silviooosilva\CacheerPhp\Contracts\Store;
use Silviooosilva\CacheerPhp\Kernel\CacheEntry;
use Silviooosilva\CacheerPhp\Kernel\Key;
use Silviooosilva\CacheerPhp\Kernel\Ttl;

/**
 * Test double that delegates to a real store but can be flipped to fail, so
 * failover, circuit-breaker, and recovery behavior can be driven deterministically.
 */
final class ToggleableStore implements Store
{
    public bool $failing = false;

    public int $attempts = 0;

    public function __construct(private readonly Store $delegate)
    {
    }

    public function get(Key $key): CacheEntry
    {
        $this->guard();

        return $this->delegate->get($key);
    }

    public function set(Key $key, mixed $value, Ttl $ttl): void
    {
        $this->guard();
        $this->delegate->set($key, $value, $ttl);
    }

    public function delete(Key $key): bool
    {
        $this->guard();

        return $this->delegate->delete($key);
    }

    public function clear(): void
    {
        $this->guard();
        $this->delegate->clear();
    }

    private function guard(): void
    {
        $this->attempts++;

        if ($this->failing) {
            throw new RuntimeException('primary store is unavailable');
        }
    }
}
