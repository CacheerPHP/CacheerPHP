<?php

use PHPUnit\Framework\TestCase;
use Silviooosilva\CacheerPhp\Cacheer;

/**
 * Tests for Cacheer::getOption().
 */
class GetOptionTest extends TestCase
{
    private Cacheer $cache;

    protected function setUp(): void
    {
        $this->cache = new Cacheer(['cacheDir' => '/tmp/test', 'ttl' => 3600]);
        $this->cache->setDriver()->useArrayDriver();
    }

    public function testReturnsExistingOption(): void
    {
        $this->assertSame('/tmp/test', $this->cache->getOption('cacheDir'));
    }

    public function testReturnsNullForMissingKey(): void
    {
        $this->assertNull($this->cache->getOption('nonExistentKey'));
    }

    public function testReturnsCustomDefaultForMissingKey(): void
    {
        $this->assertSame('fallback', $this->cache->getOption('nonExistentKey', 'fallback'));
    }

    public function testDefaultIsIgnoredWhenKeyExists(): void
    {
        $this->assertSame(3600, $this->cache->getOption('ttl', 9999));
    }

    public function testReturnsNonStringValues(): void
    {
        $this->cache->setOption('enabled', true);
        $this->cache->setOption('items', [1, 2, 3]);

        $this->assertTrue($this->cache->getOption('enabled'));
        $this->assertSame([1, 2, 3], $this->cache->getOption('items'));
    }

    public function testDefaultCanBeAnyType(): void
    {
        $this->assertSame(42, $this->cache->getOption('missing', 42));
        $this->assertFalse($this->cache->getOption('missing', false));
        $this->assertSame([], $this->cache->getOption('missing', []));
    }

    public function testWorksAfterSetOption(): void
    {
        $this->cache->setOption('newKey', 'newValue');

        $this->assertSame('newValue', $this->cache->getOption('newKey'));
    }

    public function testWorksAfterSetOptions(): void
    {
        $this->cache->setOptions(['a' => 1, 'b' => 2]);

        $this->assertSame(1, $this->cache->getOption('a'));
        $this->assertSame(2, $this->cache->getOption('b'));
        // Old options are gone after setOptions replaces all
        $this->assertNull($this->cache->getOption('cacheDir'));
    }
}
