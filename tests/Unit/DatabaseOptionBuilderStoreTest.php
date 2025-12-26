<?php

use PHPUnit\Framework\TestCase;
use Silviooosilva\CacheerPhp\Cacheer;
use Silviooosilva\CacheerPhp\Config\Option\Builder\OptionBuilder;
use Silviooosilva\CacheerPhp\Core\Connect;

class DatabaseOptionBuilderStoreTest extends TestCase
{
  private ?PDO $pdo = null;
  private string $table = 'cache_items';

  protected function setUp(): void
  {
    // Ensure SQLite connection and create a custom table for this test
    $this->pdo = Connect::getInstance();

    // Create table compatible with SQLite
    $this->pdo->exec("CREATE TABLE IF NOT EXISTS {$this->table} (
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
    if ($this->pdo instanceof PDO) {
      $this->pdo->exec("DROP TABLE IF EXISTS {$this->table}");
    }
  }

  public function test_database_store_uses_custom_table_from_option_builder()
  {
    $options = OptionBuilder::forDatabase()
      ->table($this->table)
      ->build();

    $cache = new Cacheer($options);
    $cache->setDriver()->useDatabaseDriver();

    $key = 'opt_table_key';
    $data = ['x' => 1];

    $cache->putCache($key, $data, '', 3600);
    $this->assertTrue($cache->isSuccess());

    // Validate via direct SQL against the custom table
    $nowFunction = "DATETIME('now', 'localtime')";
    $stmt = $this->pdo?->prepare("SELECT cacheData FROM {$this->table} WHERE cacheKey = :k AND cacheNamespace = '' AND expirationTime > $nowFunction LIMIT 1");
    $stmt->bindValue(':k', $key);
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    $this->assertNotFalse($row);
    $this->assertEquals($data, unserialize($row['cacheData']));

    // And ensure Cacheer can read it back through the store
    $read = $cache->getCache($key);
    $this->assertEquals($data, $read);
  }
}

