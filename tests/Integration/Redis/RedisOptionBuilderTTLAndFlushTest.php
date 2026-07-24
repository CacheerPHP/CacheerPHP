<?php

namespace Tests\Integration\Redis;

use Silviooosilva\CacheerPhp\Cacheer;
use Silviooosilva\CacheerPhp\Config\Option\Builder\OptionBuilder;
use Silviooosilva\CacheerPhp\Helpers\FlushHelper;

class RedisOptionBuilderTTLAndFlushTest extends RedisIntegrationTestCase
{
    protected function tearDown(): void
    {
        $path = FlushHelper::pathFor('redis', 'app:');
        if (is_file($path)) {
            @unlink($path);
        }

        parent::tearDown();
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
        $this->assertFalse((bool) $this->redisClient?->exists('app:to_be_flushed'));
    }
}
