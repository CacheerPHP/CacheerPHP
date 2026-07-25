<?php

declare(strict_types=1);

namespace Tests\Contract;

use Silviooosilva\CacheerPhp\Contracts\Store;
use Silviooosilva\CacheerPhp\Stores\ArrayStore;
use Silviooosilva\CacheerPhp\Stores\ResilientStore;
use Tests\Support\FakeClock;
use Tests\Support\StoreConformance;

/**
 * With a healthy primary the resilient decorator must behave exactly like the
 * store it wraps across the full base and capability contract.
 */
final class ResilientStoreConformanceTest extends StoreConformance
{
    protected function createStore(FakeClock $clock): Store
    {
        return new ResilientStore(new ArrayStore($clock), new ArrayStore($clock), clock: $clock);
    }
}
