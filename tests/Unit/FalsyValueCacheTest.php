<?php

use PHPUnit\Framework\TestCase;
use Silviooosilva\CacheerPhp\Cacheer;

/**
 * Regression tests for falsy-value cache misses (v4.x bugs).
 *
 * The old implementation used !empty() to decide whether a cache hit occurred,
 * which caused 0, 0.0, '', '0', false, and [] to be treated as misses.
 * v5.0.0 uses isSuccess() everywhere, so all of these must now round-trip
 * correctly through the cache.
 */
class FalsyValueCacheTest extends TestCase
{
    private Cacheer $cache;

    protected function setUp(): void
    {
        $this->cache = new Cacheer();
        $this->cache->setDriver()->useArrayDriver();
    }

    protected function tearDown(): void
    {
        $this->cache->flushCache();
    }

    public function testZeroCanBeCached(): void
    {
        $this->cache->putCache('zero', 0);
        $this->assertTrue($this->cache->isSuccess());

        $value = $this->cache->getCache('zero');

        $this->assertTrue($this->cache->isSuccess(), 'Integer 0 must be a cache hit.');
        $this->assertSame(0, $value);
    }

    public function testZeroPointZeroCanBeCached(): void
    {
        $this->cache->putCache('zero_float', 0.0);
        $value = $this->cache->getCache('zero_float');

        $this->assertTrue($this->cache->isSuccess(), 'Float 0.0 must be a cache hit.');
        $this->assertSame(0.0, $value);
    }

    public function testEmptyStringCanBeCached(): void
    {
        $this->cache->putCache('empty_str', '');
        $value = $this->cache->getCache('empty_str');

        $this->assertTrue($this->cache->isSuccess(), 'Empty string must be a cache hit.');
        $this->assertSame('', $value);
    }

    public function testFalseCanBeCached(): void
    {
        $this->cache->putCache('false_val', false);
        $value = $this->cache->getCache('false_val');

        $this->assertTrue($this->cache->isSuccess(), 'Boolean false must be a cache hit.');
        $this->assertFalse($value);
    }

    public function testEmptyArrayCanBeCached(): void
    {
        $this->cache->putCache('empty_arr', []);
        $value = $this->cache->getCache('empty_arr');

        $this->assertTrue($this->cache->isSuccess(), 'Empty array must be a cache hit.');
        $this->assertSame([], $value);
    }

    /**
     * remember() must not invoke the callback when a falsy value is already
     * cached — in the old implementation, !empty(0) re-executed the callback.
     */
    public function testRememberDoesNotRecallCallbackForFalsyValues(): void
    {
        $callCount = 0;

        $first = $this->cache->remember('remember_zero', 60, function () use (&$callCount) {
            $callCount++;
            return 0;
        });

        $this->assertSame(0, $first, 'First call must return 0 from the callback.');
        $this->assertSame(1, $callCount, 'Callback must be called exactly once.');

        $second = $this->cache->remember('remember_zero', 60, function () use (&$callCount) {
            $callCount++;
            return 999;
        });

        $this->assertSame(0, $second, 'Second call must return cached 0, not 999.');
        $this->assertSame(1, $callCount, 'Callback must NOT be called again for a cached falsy value.');
    }

    /**
     * increment() must work correctly when the stored value is 0,
     * since !empty(0) used to make the driver think there was no cached item.
     */
    public function testIncrementWorksFromZero(): void
    {
        $this->cache->putCache('counter', 0);
        $this->assertTrue($this->cache->isSuccess());

        $result = $this->cache->increment('counter', 1);

        $this->assertTrue($result, 'increment() must succeed when stored value is 0.');
        $this->assertSame(1, $this->cache->getCache('counter'));
    }

    /**
     * decrement() must work correctly when the stored value is 0.
     */
    public function testDecrementWorksFromZero(): void
    {
        $this->cache->putCache('counter', 0);

        $result = $this->cache->decrement('counter', 1);

        $this->assertTrue($result, 'decrement() must succeed when stored value is 0.');
        $this->assertSame(-1, $this->cache->getCache('counter'));
    }
}
