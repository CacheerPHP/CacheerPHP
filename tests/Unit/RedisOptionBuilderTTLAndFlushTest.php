<?php

use PHPUnit\Framework\TestCase;
use Silviooosilva\CacheerPhp\Cacheer;
use Silviooosilva\CacheerPhp\Config\Option\Builder\OptionBuilder;
use Silviooosilva\CacheerPhp\Helpers\FlushHelper;
use Predis\Client as PredisClient;
use Predis\Autoloader as PredisAutoloader;

class RedisOptionBuilderTTLAndFlushTest extends TestCase
{
  private ?PredisClient $client = null;

  protected function setUp(): void
  {
    try {
      PredisAutoloader::register();
      $this->client = new PredisClient([
        'scheme' => 'tcp',
        'host'   => REDIS_CONNECTION_CONFIG['REDIS_HOST'] ?? '127.0.0.1',
        'port'   => REDIS_CONNECTION_CONFIG['REDIS_PORT'] ?? 6379,
      ]);
      $this->client->connect();
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

  public function test_expiration_time_from_options_sets_default_ttl()
  {
    $options = OptionBuilder::forRedis()
      ->setNamespace('app:')
      ->expirationTime('1 seconds')
      ->build();

    $cache = new Cacheer($options);
    $cache->setDriver()->useRedisDriver();

    $key = 'redis_opt_ttl_key';
    $cache->putCache($key, 'v');
    $this->assertTrue($cache->isSuccess());

    sleep(2);
    $this->assertNull($cache->getCache($key));
  }

  public function test_flush_after_from_options_triggers_auto_flush()
  {
    $options = OptionBuilder::forRedis()
      ->setNamespace('app:')
      ->flushAfter('1 seconds')
      ->build();

    $flushFile = FlushHelper::pathFor('redis', 'app:');
    file_put_contents($flushFile, (string) (time() - 3600));

    // seed
    $seed = new Cacheer(OptionBuilder::forRedis()->setNamespace('app:')->build());
    $seed->setDriver()->useRedisDriver();
    $seed->putCache('to_be_flushed', '1');

    // new instance should auto-flush on init
    $cache = new Cacheer($options);
    $cache->setDriver()->useRedisDriver();
    $this->assertFalse((bool)$this->client?->exists('app:to_be_flushed'));
  }
}
