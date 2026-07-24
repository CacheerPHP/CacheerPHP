<?php

declare(strict_types=1);

namespace Silviooosilva\CacheerPhp\Contracts;

use Silviooosilva\CacheerPhp\Kernel\CacheEntry;
use Silviooosilva\CacheerPhp\Kernel\Scope;

interface InspectableStore
{
    /**
     * @return iterable<CacheEntry>
     */
    public function entries(?Scope $scope = null): iterable;
}
