<?php

use PHPUnit\Framework\TestCase;
use Silviooosilva\CacheerPhp\Cacheer;
use Silviooosilva\CacheerPhp\CacheStore\ArrayCacheStore;

/**
 * Tests for v5.0.0 diagnostic and static-instance management methods.
 *
 * Covered:
 *  - Cacheer::stats()        — returns driver name and feature flags
 *  - Cacheer::resetInstance() — clears the shared static instance
 *  - Cacheer::setInstance()   — injects a custom static instance
 */
class StatsAndResetTest extends TestCase
{
    protected function tearDown(): void
    {
        // Always clean up the static singleton so other test classes are not
        // affected by whatever state this suite leaves behind.
        Cacheer::resetInstance();
    }

    // -------------------------------------------------------------------------
    // stats()
    // -------------------------------------------------------------------------

    public function testStatsReturnsDriverName(): void
    {
        $cache = new Cacheer();
        $cache->setDriver()->useArrayDriver();

        $stats = $cache->stats();

        $this->assertArrayHasKey('driver', $stats);
        $this->assertStringContainsString('ArrayCacheStore', $stats['driver']);
    }

    public function testStatsCompressionFlagIsInitiallyFalse(): void
    {
        $cache = new Cacheer();
        $cache->setDriver()->useArrayDriver();

        $stats = $cache->stats();

        $this->assertArrayHasKey('compression', $stats);
        $this->assertFalse($stats['compression']);
    }

    public function testStatsCompressionFlagIsTrueAfterEnable(): void
    {
        $cache = new Cacheer();
        $cache->setDriver()->useArrayDriver();
        $cache->useCompression(true);

        $this->assertTrue($cache->stats()['compression']);
    }

    public function testStatsEncryptionFlagIsInitiallyFalse(): void
    {
        $cache = new Cacheer();
        $cache->setDriver()->useArrayDriver();

        $this->assertFalse($cache->stats()['encryption']);
    }

    public function testStatsEncryptionFlagIsTrueAfterEnable(): void
    {
        $cache = new Cacheer();
        $cache->setDriver()->useArrayDriver();
        $cache->useEncryption('some-secret-key');

        $this->assertTrue($cache->stats()['encryption']);
    }

    // -------------------------------------------------------------------------
    // resetInstance()
    // -------------------------------------------------------------------------

    public function testResetInstanceClearsStaticState(): void
    {
        // Trigger lazy creation of the static singleton.
        Cacheer::setDriver();

        Cacheer::resetInstance();

        // After reset, a new singleton is created on next access.
        // We verify this by calling setInstance() with a known object
        // and confirming that subsequent static calls use it.
        $custom = new Cacheer();
        $custom->setDriver()->useArrayDriver();
        Cacheer::setInstance($custom);

        // Static call delegates to $custom.
        Cacheer::putCache('reset_test', 'hello');
        $this->assertEquals('hello', Cacheer::getCache('reset_test'));
    }

    // -------------------------------------------------------------------------
    // setInstance()
    // -------------------------------------------------------------------------

    public function testSetInstanceReplacesStaticInstance(): void
    {
        $instance = new Cacheer();
        $instance->setDriver()->useArrayDriver();

        Cacheer::setInstance($instance);

        // The static facade must now delegate to $instance.
        Cacheer::putCache('static_key', 'static_value');

        $this->assertEquals('static_value', $instance->getCache('static_key'));
        $this->assertTrue($instance->isSuccess());
    }

    public function testGetCacheStoreReturnsActiveDriver(): void
    {
        $cache = new Cacheer();
        $cache->setDriver()->useArrayDriver();

        $this->assertInstanceOf(ArrayCacheStore::class, $cache->getCacheStore());
    }
}
