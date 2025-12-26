<?php

use PHPUnit\Framework\TestCase;
use Silviooosilva\CacheerPhp\Core\Connect;
use Silviooosilva\CacheerPhp\Core\MigrationManager;
use Silviooosilva\CacheerPhp\Repositories\CacheDatabaseRepository;

class MigrationManagerDynamicTableTest extends TestCase
{
  private ?PDO $pdo = null;
  private string $table;

  protected function setUp(): void
  {
    $this->pdo = Connect::getInstance();
    $this->table = uniqid('mm_custom_table_');
    // Ensure clean start
    $this->pdo->exec("DROP TABLE IF EXISTS {$this->table}");
  }

  protected function tearDown(): void
  {
    if ($this->pdo) {
      $this->pdo->exec("DROP TABLE IF EXISTS {$this->table}");
    }
  }

  public function test_migrate_creates_custom_table()
  {
    MigrationManager::migrate($this->pdo, $this->table);

    // Verify table exists (SQLite check)
    $stmt = $this->pdo?->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name = :t");
    $stmt->bindValue(':t', $this->table);
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $this->assertNotFalse($row);

    // Verify basic insert/select using PDO
    $expiration = date('Y-m-d H:i:s', time() + 60);
    $stmt = $this->pdo?->prepare("INSERT INTO {$this->table} (cacheKey, cacheData, cacheNamespace, expirationTime, created_at) VALUES (:k, :d, '', :e, :c)");
    $stmt->bindValue(':k', 'mk');
    $stmt->bindValue(':d', serialize(['a' => 1]));
    $stmt->bindValue(':e', $expiration);
    $stmt->bindValue(':c', date('Y-m-d H:i:s'));
    $this->assertTrue($stmt->execute());

    $stmt = $this->pdo?->prepare("SELECT cacheData FROM {$this->table} WHERE cacheKey = :k LIMIT 1");
    $stmt->bindValue(':k', 'mk');
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $this->assertEquals(['a' => 1], unserialize($row['cacheData']));
  }

  public function test_default_constant_table_exists()
  {
    // With boot autoload, the default CACHEER_TABLE should be created via Connect::getInstance()
    $stmt = $this->pdo?->query("SELECT name FROM sqlite_master WHERE type='table' AND name = 'cacheer_table'");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $this->assertNotFalse($row);
  }
}
