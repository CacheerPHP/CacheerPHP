<?php

namespace Tests\Integration\Database;

use PDO;
use PHPUnit\Framework\TestCase;
use Silviooosilva\CacheerPhp\Boot\RuntimeConfig;
use Silviooosilva\CacheerPhp\Cacheer;
use Silviooosilva\CacheerPhp\Core\Connect;
use Throwable;

class DatabaseCacheStoreIntegrationTest extends TestCase
{
    private const TABLE = 'cacheer_integration';

    private ?Cacheer $cache = null;

    private ?PDO $pdo = null;

    protected function setUp(): void
    {
        parent::setUp();

        RuntimeConfig::reset();
        $driver = getenv('DB_CONNECTION') ?: 'sqlite';

        try {
            Connect::setConnection($driver);
            $this->pdo = Connect::getInstance();
            $this->cache = new Cacheer(['table' => self::TABLE]);
            $this->cache->setDriver()->useDatabaseDriver();
            $this->cache->flushCache();
        } catch (Throwable $exception) {
            $message = sprintf('%s integration service is unavailable: %s', $driver, $exception->getMessage());
            if (getenv('CACHEER_REQUIRE_DATABASE') === '1') {
                self::fail($message);
            }

            self::markTestSkipped($message);
        }
    }

    protected function tearDown(): void
    {
        if ($this->cache instanceof Cacheer) {
            $this->cache->flushCache();
        }

        if ($this->pdo instanceof PDO) {
            $this->pdo->exec('DROP TABLE IF EXISTS ' . self::TABLE);
        }

        $this->cache = null;
        $this->pdo = null;
        RuntimeConfig::reset();

        parent::tearDown();
    }

    public function test_round_trips_values_and_namespaces(): void
    {
        $this->assertTrue($this->cache?->putCache('profile', ['id' => 42], 'tenant'));
        $this->assertSame(['id' => 42], $this->cache?->getCache('profile', 'tenant'));
        $this->assertNull($this->cache?->getCache('profile', 'other'));
    }

    public function test_batches_and_invalidates_tagged_values(): void
    {
        $this->assertTrue($this->cache?->putMany([
            'first'  => ['value' => 1],
            'second' => ['value' => 2],
        ], 'batch'));

        $this->assertSame([
            'first'  => ['value' => 1],
            'second' => ['value' => 2],
        ], $this->cache?->getMany(['first', 'second'], 'batch'));

        $this->assertTrue($this->cache?->tag('batch-values', 'batch:first', 'batch:second'));
        $this->assertTrue($this->cache?->flushTag('batch-values'));
        $this->assertFalse($this->cache?->has('first', 'batch'));
        $this->assertFalse($this->cache?->has('second', 'batch'));
    }

    public function test_database_lock_excludes_competing_owner(): void
    {
        $first = $this->cache?->lock('integration-lock', 10);
        $second = $this->cache?->lock('integration-lock', 10);

        $this->assertTrue($first?->acquire());
        $this->assertFalse($second?->acquire());
        $this->assertTrue($first?->release());
        $this->assertTrue($second?->acquire());
        $this->assertTrue($second?->release());
    }

    public function test_renews_an_unexpired_value(): void
    {
        $this->assertTrue($this->cache?->putCache('renewable', 'value', '', 60));

        $before = $this->expirationFor('renewable');
        $this->assertTrue($this->cache?->renewCache('renewable', 120));
        $after = $this->expirationFor('renewable');

        $this->assertGreaterThan(strtotime($before), strtotime($after));
        $this->assertSame('value', $this->cache?->getCache('renewable'));
    }

    private function expirationFor(string $key): string
    {
        $statement = $this->pdo?->prepare(
            'SELECT expirationTime FROM ' . self::TABLE . ' WHERE cacheKey = :key LIMIT 1',
        );
        $statement?->execute([':key' => $key]);
        $expiration = $statement?->fetchColumn();

        self::assertIsString($expiration);

        return $expiration;
    }
}
