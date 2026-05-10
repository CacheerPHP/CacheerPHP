<?php

use PHPUnit\Framework\TestCase;
use Silviooosilva\CacheerPhp\Cacheer;

/**
 * Verifies the convenience alias methods on Cacheer:
 *
 *  - forget()  ⇄ clearCache()
 *  - pull()    ⇄ getAndForget()
 *  - missing() ⇄ !has()
 *
 * The aliases must behave identically to the underlying methods when called
 * either on an instance or through the static facade.
 */
class AliasMethodsTest extends TestCase
{
    private Cacheer $cache;

    protected function setUp(): void
    {
        $this->cache = new Cacheer();
        $this->cache->setDriver()->useArrayDriver();
    }

    public function testForgetIsEquivalentToClearCache(): void
    {
        $this->cache->putCache('alpha', 1);
        $this->assertTrue($this->cache->has('alpha'));

        $this->assertTrue($this->cache->forget('alpha'));
        $this->assertFalse($this->cache->has('alpha'));
    }

    public function testForgetMatchesClearCacheReturnValue(): void
    {
        // Both return whatever isSuccess() reports after the underlying delete.
        $this->cache->putCache('a', 1);
        $a = $this->cache->forget('a');

        $this->cache->putCache('b', 1);
        $b = $this->cache->clearCache('b');

        $this->assertSame($a, $b);
    }

    public function testPullReturnsValueAndRemovesKey(): void
    {
        $this->cache->putCache('temp', 'short-lived');

        $this->assertSame('short-lived', $this->cache->pull('temp'));
        $this->assertFalse($this->cache->has('temp'));
    }

    public function testPullReturnsNullForMissingKey(): void
    {
        $this->assertNull($this->cache->pull('absent'));
    }

    public function testMissingReturnsTrueWhenAbsent(): void
    {
        $this->assertTrue($this->cache->missing('not-there'));
    }

    public function testMissingReturnsFalseWhenPresent(): void
    {
        $this->cache->putCache('here', 'yes');
        $this->assertFalse($this->cache->missing('here'));
    }

    public function testAliasesAvailableThroughStaticFacade(): void
    {
        Cacheer::resetInstance();
        try {
            Cacheer::putCache('x', 'y');
            $this->assertFalse(Cacheer::missing('x'));

            $this->assertSame('y', Cacheer::pull('x'));
            $this->assertTrue(Cacheer::missing('x'));
        } finally {
            Cacheer::resetInstance();
        }
    }
}
