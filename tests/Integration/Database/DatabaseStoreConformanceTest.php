<?php

declare(strict_types=1);

namespace Tests\Integration\Database;

use PDO;
use Silviooosilva\CacheerPhp\Contracts\Store;
use Silviooosilva\CacheerPhp\Stores\DatabaseStore;
use Silviooosilva\CacheerPhp\Stores\Support\DatabaseStoreSchema;
use Tests\Support\FakeClock;
use Tests\Support\StoreConformance;

final class DatabaseStoreConformanceTest extends StoreConformance
{
    private PDO $pdo;

    protected function createStore(FakeClock $clock): Store
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        DatabaseStoreSchema::migrate($this->pdo, 'cacheer_store');

        return new DatabaseStore($this->pdo, 'cacheer_store', clock: $clock);
    }
}
