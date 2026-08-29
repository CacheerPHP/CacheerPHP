<?php

declare(strict_types=1);

namespace Silviooosilva\CacheerPhp\Contracts;

/**
 * A held, releasable mutex handed out by a {@see LockingStore}.
 *
 * Locks carry a TTL and self-expire, so a crashed holder cannot deadlock the
 * keyspace, and release is owner-scoped: a lock only ever deletes its own token.
 */
interface Lock
{
    /**
     * @return bool
     */
    public function acquire(): bool;

    /**
     * @param float $seconds
     * @return bool
     */
    public function block(float $seconds): bool;

    /**
     * @return bool
     */
    public function release(): bool;
}
