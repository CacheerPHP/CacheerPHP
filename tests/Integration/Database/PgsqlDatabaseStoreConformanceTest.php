<?php

declare(strict_types=1);

namespace Tests\Integration\Database;

use PDO;
use Silviooosilva\CacheerPhp\Contracts\Store;
use Silviooosilva\CacheerPhp\Stores\DatabaseStore;
use Silviooosilva\CacheerPhp\Stores\Support\DatabaseStoreSchema;
use Tests\Support\FakeClock;
use Tests\Support\StoreConformance;

/**
 * Runs the shared store conformance suite against a real PostgreSQL server,
 * exercising the ON CONFLICT upsert and FOR UPDATE row-locked counters. Skips
 * where no server is configured (e.g. locally); runs in the CI matrix.
 */
final class PgsqlDatabaseStoreConformanceTest extends StoreConformance
{
    private const TABLE = 'cacheer_store';

    private PDO $pdo;

    protected function createStore(FakeClock $clock): Store
    {
        // See the note in MySqlDatabaseStoreConformanceTest: DB_HOST/DB_PORT are
        // shared, so DB_CONNECTION is what says which server is really running.
        if ((getenv('DB_CONNECTION') ?: '') !== 'pgsql') {
            self::markTestSkipped('DB_CONNECTION is not "pgsql"; no PostgreSQL server to test against.');
        }

        $host = getenv('DB_HOST') ?: '127.0.0.1';
        $port = getenv('DB_PORT') ?: '5432';
        $name = getenv('DB_DATABASE') ?: 'cacheer_db';
        $user = getenv('DB_USERNAME') ?: 'postgres';
        $pass = getenv('DB_PASSWORD') ?: 'postgres';

        try {
            $this->pdo = new PDO(
                sprintf('pgsql:host=%s;port=%s;dbname=%s;connect_timeout=5', $host, $port, $name),
                $user,
                $pass,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5],
            );
        } catch (\Throwable $exception) {
            self::markTestSkipped('PostgreSQL is not available: ' . $exception->getMessage());
        }

        DatabaseStoreSchema::drop($this->pdo, self::TABLE);
        DatabaseStoreSchema::migrate($this->pdo, self::TABLE);

        return new DatabaseStore($this->pdo, self::TABLE, clock: $clock);
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo)) {
            DatabaseStoreSchema::drop($this->pdo, self::TABLE);
        }

        parent::tearDown();
    }
}
