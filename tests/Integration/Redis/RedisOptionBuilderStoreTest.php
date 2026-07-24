<?php

namespace Tests\Integration\Redis;

use Silviooosilva\CacheerPhp\Cacheer;
use Silviooosilva\CacheerPhp\Config\Option\Builder\OptionBuilder;

class RedisOptionBuilderStoreTest extends RedisIntegrationTestCase
{
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
        $this->assertTrue((bool) $this->redisClient?->exists('app:' . $key));

        $read = $cache->getCache($key);
        $this->assertEquals($data, $read);
    }
}
