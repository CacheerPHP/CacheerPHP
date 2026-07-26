<?php

declare(strict_types=1);

namespace Tests\Contract;

use Silviooosilva\CacheerPhp\Contracts\Store;
use Silviooosilva\CacheerPhp\Observability\NullEventDispatcher;
use Silviooosilva\CacheerPhp\Stores\ArrayStore;
use Silviooosilva\CacheerPhp\Stores\InstrumentedStore;
use Tests\Support\FakeClock;
use Tests\Support\StoreConformance;

/**
 * Instrumentation must be transparent: wrapping a store changes nothing about
 * its behavior across the full contract.
 */
final class InstrumentedStoreConformanceTest extends StoreConformance
{
    protected function createStore(FakeClock $clock): Store
    {
        return new InstrumentedStore(new ArrayStore($clock), new NullEventDispatcher());
    }
}
