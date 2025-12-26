<?php

use PHPUnit\Framework\TestCase;
use Silviooosilva\CacheerPhp\Cacheer;
use Silviooosilva\CacheerPhp\Config\Option\Builder\OptionBuilder;
use Predis\Client as PredisClient;
use Predis\Autoloader as PredisAutoloader;

class RedisOptionBuilderStoreTest extends TestCase
{
  private ?PredisClient $client = null;

  protected function setUp(): void
  {
    // Try to connect to Redis; skip if not available
    try {
      PredisAutoloader::register();
      $this->client = new PredisClient([
        'scheme' => 'tcp',
        'host'   => REDIS_CONNECTION_CONFIG['REDIS_HOST'] ?? '127.0.0.1',
        'port'   => REDIS_CONNECTION_CONFIG['REDIS_PORT'] ?? 6379,
      ]);
      $this->client->connect();
      // simple call to verify
      $this->client->ping();
    } catch (\Throwable $e) {
      $this->markTestSkipped('Redis not available: ' . $e->getMessage());
    }
  }

  protected function tearDown(): void
  {
    if ($this->client) {
      $this->client->disconnect();
    }
  }

  public function test_redis_store_uses_namespace_from_option_builder()
  {
    $options = OptionBuilder::forRedis()
      ->setNamespace('app:')
      ->build();

    $cache = new Cacheer($options);
    $cache->setDriver()->useRedisDriver();

    $key = 'rb_key';
    $data = ['v' => 1];

    $cache->putCache($key, $data);
    $this->assertTrue($cache->isSuccess());

    // Should be stored with prefix 'app:'
    $this->assertTrue((bool)$this->client?->exists('app:' . $key));

    $read = $cache->getCache($key);
    $this->assertEquals($data, $read);
  }
}

