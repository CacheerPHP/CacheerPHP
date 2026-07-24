<?php

declare(strict_types=1);

namespace Tests\Integration\Database;

use PDO;
use Silviooosilva\CacheerPhp\Boot\RuntimeConfig;
use Silviooosilva\CacheerPhp\Cacheer;
use Silviooosilva\CacheerPhp\Core\Connect;
use Tests\Contract\StoreContractTestCase;
use Tests\Support\FakeClock;
use Throwable;

final class DatabaseStoreContractTest extends StoreContractTestCase
{
    private const TABLE = 'cacheer_contract';

    private ?PDO $pdo = null;

    protected function createCache(FakeClock $clock): Cacheer
    {
        RuntimeConfig::reset();
        $driver = getenv('DB_CONNECTION') ?: 'sqlite';

        try {
            Connect::setConnection($driver);
            $this->pdo = Connect::getInstance();
            $cache = new Cacheer(['table' => self::TABLE, 'clock' => $clock]);
            $cache->setDriver()->useDatabaseDriver();

            return $cache;
        } catch (Throwable $exception) {
            $message = sprintf('%s contract service is unavailable: %s', $driver, $exception->getMessage());
            if (getenv('CACHEER_REQUIRE_DATABASE') === '1') {
                self::fail($message);
            }

            self::markTestSkipped($message);
        }
    }

    protected function advanceTime(float $seconds): void
    {
        usleep((int) ceil($seconds * 1_000_000));
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->pdo?->exec('DROP TABLE IF EXISTS ' . self::TABLE);
        $this->pdo = null;
        RuntimeConfig::reset();
    }
}
