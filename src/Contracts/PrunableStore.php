<?php

declare(strict_types=1);

namespace Silviooosilva\CacheerPhp\Contracts;

/**
 * Eager removal of expired entries.
 *
 * Expiry is lazy on read regardless; this is the bulk sweep, for a cron or the CLI.
 */
interface PrunableStore
{
    /**
     * Remove expired entries and return the number removed.
     *
     * @return int
     */
    public function prune(): int;
}
