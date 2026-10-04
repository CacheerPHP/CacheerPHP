<?php

declare(strict_types=1);

namespace Tests\Support;

use RuntimeException;
use Silviooosilva\CacheerPhp\Contracts\AtomicStore;
use Silviooosilva\CacheerPhp\Contracts\CapabilityAware;
use Silviooosilva\CacheerPhp\Contracts\Lock;
use Silviooosilva\CacheerPhp\Contracts\LockingStore;
use Silviooosilva\CacheerPhp\Contracts\Store;
use Silviooosilva\CacheerPhp\Kernel\CacheEntry;
use Silviooosilva\CacheerPhp\Kernel\Capabilities;
use Silviooosilva\CacheerPhp\Kernel\Key;
use Silviooosilva\CacheerPhp\Kernel\Ttl;
use Throwable;

/**
 * Test double that delegates to a real store but can be flipped to fail, so
 * failover, circuit-breaker, and recovery behavior can be driven deterministically.
 *
 * Counters and locks are forwarded (and claimed) only when the delegate has
 * them, and fail with the store when it is flipped.
 */
final class ToggleableStore implements Store, AtomicStore, LockingStore, CapabilityAware
{
    public bool $failing = false;

    /**
     * What a failing store throws; an availability error by default.
     */
    public ?Throwable $failWith = null;

    public int $attempts = 0;

    public function __construct(private readonly Store $delegate)
    {
    }

    public function supports(string $capability): bool
    {
        return $capability === Store::class || Capabilities::supports($this->delegate, $capability);
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

    public function increment(Key $key, int $amount = 1, ?int $initial = null, ?Ttl $ttl = null): int
    {
        $this->guard();

        return Capabilities::require($this->delegate, AtomicStore::class, 'increment')->increment($key, $amount, $initial, $ttl);
    }

    public function compareAndSwap(Key $key, mixed $expected, mixed $value, ?Ttl $ttl = null): bool
    {
        $this->guard();

        return Capabilities::require($this->delegate, AtomicStore::class, 'compareAndSwap')->compareAndSwap($key, $expected, $value, $ttl);
    }

    public function lock(string $name, Ttl $ttl): Lock
    {
        $this->guard();
        $inner = Capabilities::require($this->delegate, LockingStore::class, 'lock')->lock($name, $ttl);
        $guard = $this->guard(...);

        return new class ($inner, $guard) implements Lock {
            public function __construct(private readonly Lock $inner, private readonly \Closure $guard)
            {
            }

            public function acquire(): bool
            {
                ($this->guard)();

                return $this->inner->acquire();
            }

            public function block(float $seconds): bool
            {
                ($this->guard)();

                return $this->inner->block($seconds);
            }

            public function release(): bool
            {
                ($this->guard)();

                return $this->inner->release();
            }
        };
    }

    private function guard(): void
    {
        $this->attempts++;

        if ($this->failing) {
            throw $this->failWith ?? new RuntimeException('primary store is unavailable');
        }
    }
}
