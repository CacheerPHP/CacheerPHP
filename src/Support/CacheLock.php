<?php

namespace Silviooosilva\CacheerPhp\Support;

use Closure;
use Silviooosilva\CacheerPhp\Interface\LockProviderInterface;

/**
 * Class CacheLock
 *
 * Ergonomic, driver-agnostic handle around a store's LockProviderInterface.
 * Acquire/release a named lock, optionally blocking, or run a callback while
 * holding it. A random owner token scopes release to this holder only.
 *
 * @author Sílvio Silva <https://github.com/silviooosilva>
 * @package Silviooosilva\CacheerPhp
 */
final class CacheLock
{
    /**
     * @var string Owner token identifying this holder.
     */
    private string $owner;

    /**
     * @param LockProviderInterface $provider The store backing the lock.
     * @param string                $name     Lock name.
     * @param int                   $ttl      Lock lifetime in seconds.
     * @param string|null           $owner    Optional explicit owner token.
     */
    public function __construct(
        private readonly LockProviderInterface $provider,
        private readonly string $name,
        private readonly int $ttl = 60,
        ?string $owner = null,
    ) {
        $this->owner = $owner ?? bin2hex(random_bytes(16));
    }

    /**
     * Try to acquire the lock once, without blocking.
     *
     * @return bool
     */
    public function acquire(): bool
    {
        return $this->provider->lockAcquire($this->name, $this->owner, $this->ttl);
    }

    /**
     * Release the lock, but only if this holder still owns it.
     *
     * @return bool
     */
    public function release(): bool
    {
        return $this->provider->lockRelease($this->name, $this->owner);
    }

    /**
     * Try to acquire without blocking. With a callback, run it under the lock
     * and release afterwards, returning its result; returns false if the lock
     * could not be acquired. Without a callback, behaves like acquire().
     *
     * @param Closure|null $callback
     * @return mixed
     */
    public function get(?Closure $callback = null): mixed
    {
        if ($callback === null) {
            return $this->acquire();
        }

        if (!$this->acquire()) {
            return false;
        }

        try {
            return $callback();
        } finally {
            $this->release();
        }
    }

    /**
     * Block until the lock is acquired or $seconds elapses (polling).
     *
     * With a callback, run it under the lock and release afterwards, returning
     * its result; returns false if the lock was never acquired. Without a
     * callback, returns whether the lock was acquired.
     *
     * @param int          $seconds  Maximum time to wait.
     * @param Closure|null $callback
     * @return mixed
     */
    public function block(int $seconds, ?Closure $callback = null): mixed
    {
        $deadline = microtime(true) + $seconds;
        $acquired = $this->acquire();

        while (!$acquired && microtime(true) < $deadline) {
            usleep(50_000); // 50ms — fine enough to keep contended counters moving
            $acquired = $this->acquire();
        }

        if ($callback === null) {
            return $acquired;
        }

        if (!$acquired) {
            return false;
        }

        try {
            return $callback();
        } finally {
            $this->release();
        }
    }

    /**
     * The owner token identifying this lock holder.
     *
     * @return string
     */
    public function owner(): string
    {
        return $this->owner;
    }
}
