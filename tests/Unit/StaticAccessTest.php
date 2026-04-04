<?php

use PHPUnit\Framework\TestCase;
use Silviooosilva\CacheerPhp\Cacheer;
use Silviooosilva\CacheerPhp\Config\Option\Builder\OptionBuilder;

final class StaticAccessTest extends TestCase
{
    protected function tearDown(): void
    {
        // Reset the static singleton so other tests are not affected.
        Cacheer::resetInstance();
    }

    public function testFlushCacheStatic(): void
    {
        $result = Cacheer::flushCache();
        $this->assertIsBool($result);
    }

    public function testFlushCacheDynamic(): void
    {
        $cache = new Cacheer();
        $this->assertIsBool($cache->flushCache());
    }

    public function testSetUp(): void
    {
        $cache = new Cacheer();
        $options = [
            'driver' => 'file',
            'path'   => '/tmp/cache',
        ];
        $cache->setUp($options);
        // cacheStore is now private; use getOptions() accessor instead.
        $this->assertSame($options, $cache->getOptions());
    }

    public static function testSetUpStatic(): void
    {
        $options = [
            'driver' => 'file',
            'path'   => '/tmp/cache',
        ];
        Cacheer::setUp($options);
        // Cacheer::getOptions() cannot be called statically (it is an instance method).
        // Use the CacheConfig facade (setConfig()) which wraps the static instance.
        self::assertSame($options, Cacheer::/** @scrutinizer ignore-call */ setConfig()->getOptions());
    }

    public function testSetUpStaticWithOptionBuilder(): void
    {
        $options = OptionBuilder::forFile()
            ->dir('/tmp/cache')
            ->loggerPath('/tmp/cache/logs')
            ->flushAfter()->hour(2)
            ->build();

        Cacheer::setUp($options);
        // Same approach: go through CacheConfig to read options from the static instance.
        self::assertSame($options, Cacheer::/** @scrutinizer ignore-call */ setConfig()->getOptions());
    }
}
