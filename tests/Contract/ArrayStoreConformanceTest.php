<?php

declare(strict_types=1);

namespace Tests\Contract;

use Silviooosilva\CacheerPhp\Contracts\Store;
use Silviooosilva\CacheerPhp\Stores\ArrayStore;
use Tests\Support\FakeClock;
use Tests\Support\StoreConformance;

final class ArrayStoreConformanceTest extends StoreConformance
{
    protected function createStore(FakeClock $clock): Store
    {
        return new ArrayStore($clock);
    }
}
