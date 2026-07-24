<?php

declare(strict_types=1);

namespace Tests\Contract;

use Silviooosilva\CacheerPhp\Cacheer;
use Tests\Support\FakeClock;

final class ArrayStoreContractTest extends StoreContractTestCase
{
    protected function createCache(FakeClock $clock): Cacheer
    {
        $directory = sys_get_temp_dir() . '/cacheer-contract-array-' . bin2hex(random_bytes(4));
        $cache = new Cacheer(['cacheDir' => $directory, 'clock' => $clock]);
        $cache->setDriver()->useArrayDriver();

        return $cache;
    }
}
