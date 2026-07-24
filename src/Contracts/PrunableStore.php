<?php

declare(strict_types=1);

namespace Silviooosilva\CacheerPhp\Contracts;

interface PrunableStore
{
    /**
     * Remove expired entries and return the number removed.
     */
    public function prune(): int;
}
