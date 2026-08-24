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
 * Runs the shared store conformance suite against a real MySQL/MariaDB server,
 * exercising the ON DUPLICATE KEY upsert and FOR UPDATE row-locked counters.
 * Skips where no server is configured (e.g. locally); runs in the CI matrix.
 */
final class MySqlDatabaseStoreConformanceTest extends StoreConformance
{
    private const TABLE = 'cacheer_store';

    private PDO $pdo;

    protected function createStore(FakeClock $clock): Store
    {
        // DB_HOST/DB_PORT are shared across the driver suites, so they point at
        // whichever server the current job started. Connecting a MySQL client to
        // another driver's port is not a clean failure: the MySQL handshake waits
        // for the server to speak first, and PostgreSQL waits for the client, so
        // the two block on each other until the job is killed. DB_CONNECTION is
        // what says which server is actually running.
        if ((getenv('DB_CONNECTION') ?: '') !== 'mysql') {
            self::markTestSkipped('DB_CONNECTION is not "mysql"; no MySQL server to test against.');
        }

        $host = getenv('DB_HOST') ?: '127.0.0.1';
        $port = getenv('DB_PORT') ?: '3306';
        $name = getenv('DB_DATABASE') ?: 'cacheer_db';
        $user = getenv('DB_USERNAME') ?: 'root';
        $pass = getenv('DB_PASSWORD') ?: '';

        $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5];

        // ATTR_TIMEOUT only bounds the TCP connect. Bound the read too, so a
        // misconfigured port can never hang the suite again.
        if (defined('PDO::MYSQL_ATTR_READ_TIMEOUT')) {
            $options[PDO::MYSQL_ATTR_READ_TIMEOUT] = 5;
        }

        try {
            $this->pdo = new PDO(
                sprintf('mysql:host=%s;port=%s;dbname=%s', $host, $port, $name),
                $user,
                $pass,
                $options,
            );
        } catch (\Throwable $exception) {
            self::markTestSkipped('MySQL is not available: ' . $exception->getMessage());
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
