<?php

declare(strict_types=1);

namespace Silviooosilva\CacheerPhp\Contracts;

use Silviooosilva\CacheerPhp\Kernel\CacheEntry;
use Silviooosilva\CacheerPhp\Kernel\Key;
use Silviooosilva\CacheerPhp\Kernel\Ttl;

interface BatchStore
{
    /**
     * Results must preserve the input order and include misses.
     *
     * @param iterable<Key> $keys
     * @return list<CacheEntry>
     */
    public function getMany(iterable $keys): array;

    /**
     * @param iterable<array{key: Key, value: mixed}> $entries
     */
    public function setMany(iterable $entries, Ttl $ttl): void;

    /**
     * @param iterable<Key> $keys
     */
    public function deleteMany(iterable $keys): bool;
}
