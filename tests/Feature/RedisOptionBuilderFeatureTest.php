<?php

use PHPUnit\Framework\TestCase;
use Silviooosilva\CacheerPhp\Config\Option\Builder\OptionBuilder;

class RedisOptionBuilderFeatureTest extends TestCase
{
    public function test_it_builds_redis_options()
    {
        $options = OptionBuilder::forRedis()
          ->setNamespace('app:')
          ->loggerPath(__DIR__ . '/cache/logger.log')
          ->expirationTime('2 hours')
          ->flushAfter('1 day')
          ->build();

        $this->assertArrayHasKey('namespace', $options);
        $this->assertArrayHasKey('loggerPath', $options);
        $this->assertArrayHasKey('expirationTime', $options);
        $this->assertArrayHasKey('flushAfter', $options);

        $this->assertSame('app:', $options['namespace']);
        $this->assertSame(__DIR__ . '/cache/logger.log', $options['loggerPath']);
        $this->assertSame('2 hours', $options['expirationTime']);
        $this->assertSame('1 day', $options['flushAfter']);
    }

    public function test_it_allows_timebuilder_for_redis()
    {
        $options = OptionBuilder::forRedis()
          ->expirationTime()->minute(30)
          ->flushAfter()->day(2)
          ->build();

        $this->assertSame('30 minutes', $options['expirationTime']);
        $this->assertSame('2 days', $options['flushAfter']);
    }
}
