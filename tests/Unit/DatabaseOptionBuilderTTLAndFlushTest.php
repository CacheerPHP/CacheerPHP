<?php

use PHPUnit\Framework\TestCase;
use Silviooosilva\CacheerPhp\Cacheer;
use Silviooosilva\CacheerPhp\Config\Option\Builder\OptionBuilder;
use Silviooosilva\CacheerPhp\Helpers\FlushHelper;

class DatabaseOptionBuilderTTLAndFlushTest extends TestCase
{
  private string $table = 'cache_items_ttl_flush';

  protected function setUp(): void
  {
    // Ensure the custom table exists (SQLite-compatible DDL)
    $pdo = Silviooosilva\CacheerPhp\Core\Connect::getInstance();
    $pdo->exec("CREATE TABLE IF NOT EXISTS {$this->table} (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        cacheKey VARCHAR(255) NOT NULL,
        cacheData TEXT NOT NULL,
        cacheNamespace VARCHAR(255),
        expirationTime DATETIME NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE(cacheKey, cacheNamespace)
    );
    CREATE INDEX IF NOT EXISTS idx_{$this->table}_cacheKey ON {$this->table} (cacheKey);
    CREATE INDEX IF NOT EXISTS idx_{$this->table}_cacheNamespace ON {$this->table} (cacheNamespace);
    CREATE INDEX IF NOT EXISTS idx_{$this->table}_expirationTime ON {$this->table} (expirationTime);
    CREATE INDEX IF NOT EXISTS idx_{$this->table}_key_namespace ON {$this->table} (cacheKey, cacheNamespace);
    ");
  }

  protected function tearDown(): void
  {
    // clean up flush file
    $path = FlushHelper::pathFor('db', $this->table);
    if (file_exists($path)) {
      @unlink($path);
    }
    $pdo = Silviooosilva\CacheerPhp\Core\Connect::getInstance();
    $pdo->exec("DROP TABLE IF EXISTS {$this->table}");
  }

  public function test_expiration_time_from_options_sets_default_ttl()
  {
    $options = OptionBuilder::forDatabase()
      ->table($this->table)
      ->expirationTime('-1 seconds')
      ->build();

    $cache = new Cacheer($options);
    $cache->setDriver()->useDatabaseDriver();

    $key = 'db_opt_ttl_key';
    $data = ['a' => 1];
    $cache->putCache($key, $data); // default ttl should be overridden to past time
    $this->assertTrue($cache->isSuccess());

    $pdo = Silviooosilva\CacheerPhp\Core\Connect::getInstance();
    $stmt = $pdo->prepare("SELECT expirationTime FROM {$this->table} WHERE cacheKey = :k LIMIT 1");
    $stmt->bindValue(':k', $key);
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $this->assertNotFalse($row);
    $this->assertLessThanOrEqual(time(), strtotime($row['expirationTime']));
  }

  public function test_flush_after_from_options_triggers_auto_flush()
  {
    $options = OptionBuilder::forDatabase()
      ->table($this->table)
      ->flushAfter('1 seconds')
      ->build();

    // Pre-create an old last flush file to force a flush on init
    $flushFile = FlushHelper::pathFor('db', $this->table);
    file_put_contents($flushFile, (string) (time() - 3600));

    // Seed data using a cache without flushAfter
    $seed = new Cacheer(OptionBuilder::forDatabase()->table($this->table)->build());
    $seed->setDriver()->useDatabaseDriver();
    $seed->putCache('to_be_flushed', 'x');
    $this->assertTrue($seed->isSuccess());

    // New instance with auto-flush should clear table on construct
    $cache = new Cacheer($options);
    $cache->setDriver()->useDatabaseDriver();

    $this->assertEmpty($cache->getAll());
  }
}
