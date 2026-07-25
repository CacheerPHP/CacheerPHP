<?php

declare(strict_types=1);

namespace Tests\Contract;

use Silviooosilva\CacheerPhp\Contracts\Store;
use Silviooosilva\CacheerPhp\Stores\ArrayStore;
use Silviooosilva\CacheerPhp\Stores\TieredStore;
use Tests\Support\FakeClock;
use Tests\Support\StoreConformance;

/**
 * A tiered store composed of two array layers must honor the full base and
 * capability contract just like a single store.
 */
final class TieredStoreConformanceTest extends StoreConformance
{
    protected function createStore(FakeClock $clock): Store
    {
        return new TieredStore(new ArrayStore($clock), new ArrayStore($clock), $clock);
    }
}
