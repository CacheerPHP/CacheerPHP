<?php

namespace Silviooosilva\CacheerPhp\Interface;

/**
 * Interface LockProviderInterface
 *
 * Optional capability implemented by cache stores that can provide an atomic,
 * mutually-exclusive lock. Resolved by Cacheer::lock() through the CacheLock
 * value object. Stores that do not implement this simply don't support locking.
 *
 * @author Sílvio Silva <https://github.com/silviooosilva>
 * @package Silviooosilva\CacheerPhp
 */
interface LockProviderInterface
{
    /**
     * Atomically acquire a named lock.
     *
     * Returns true only if the caller obtained the lock. $owner is a
     * caller-unique token used to scope release so a holder can only release
     * its own lock (never one that expired and was re-acquired by someone else).
     *
     * @param string $name  Lock name.
     * @param string $owner Caller-unique owner token.
     * @param int    $ttl   Lock lifetime in seconds.
     * @return bool
     */
    public function lockAcquire(string $name, string $owner, int $ttl): bool;

    /**
     * Release a named lock, but only if it is still held by $owner.
     *
     * @param string $name
     * @param string $owner
     * @return bool True if this owner held the lock and it was released.
     */
    public function lockRelease(string $name, string $owner): bool;
}
