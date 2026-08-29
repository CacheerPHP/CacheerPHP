<?php

declare(strict_types=1);

namespace Silviooosilva\CacheerPhp\Contracts;

use Silviooosilva\CacheerPhp\Kernel\Ttl;

/**
 * Named cross-process mutexes.
 *
 * The kernel uses these for single-flight remember() and stale refresh; without
 * them those degrade to a plain compute rather than failing.
 */
interface LockingStore
{
    /**
     * @param string $name
     * @param Ttl $ttl
     * @return Lock
     */
    public function lock(string $name, Ttl $ttl): Lock;
}
