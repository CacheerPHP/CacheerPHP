<?php

declare(strict_types=1);

namespace Silviooosilva\CacheerPhp\Contracts;

use Silviooosilva\CacheerPhp\Kernel\CacheEntry;
use Silviooosilva\CacheerPhp\Kernel\Scope;

/**
 * Walking the live keyspace, entry metadata included.
 *
 * Backs the CLI's inspect and stats commands; expired entries are skipped.
 */
interface InspectableStore
{
    /**
     * @param ?Scope $scope
     * @return iterable<CacheEntry>
     */
    public function entries(?Scope $scope = null): iterable;
}
