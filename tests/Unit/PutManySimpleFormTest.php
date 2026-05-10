<?php

use PHPUnit\Framework\TestCase;
use Silviooosilva\CacheerPhp\Cacheer;

/**
 * Verifies the two input shapes accepted by putMany():
 *
 *   - Legacy explicit form: [['cacheKey' => 'k', 'cacheData' => $v], ...]
 *   - Simple associative form: ['k' => $v, ...]
 *
 * Both shapes — and any mix of the two in the same call — must produce the
 * same end state. Behaviour with namespaces and nested array values is also
 * exercised.
 */
class PutManySimpleFormTest extends TestCase
{
    private Cacheer $cache;

    protected function setUp(): void
    {
        $this->cache = new Cacheer();
        $this->cache->setDriver()->useArrayDriver();
    }

    public function testSimpleAssociativeForm(): void
    {
        $this->cache->putMany([
            'k1' => 'v1',
            'k2' => 'v2',
        ]);

        $this->assertSame('v1', $this->cache->getCache('k1'));
        $this->assertSame('v2', $this->cache->getCache('k2'));
    }

    public function testLegacyExplicitFormStillWorks(): void
    {
        $this->cache->putMany([
            ['cacheKey' => 'k1', 'cacheData' => 'v1'],
            ['cacheKey' => 'k2', 'cacheData' => 'v2'],
        ]);

        $this->assertSame('v1', $this->cache->getCache('k1'));
        $this->assertSame('v2', $this->cache->getCache('k2'));
    }

    public function testMixedLegacyAndSimpleEntries(): void
    {
        // The normaliser tolerates a mix — entries with cacheKey/cacheData are
        // passed through, others are treated as the simple form.
        $this->cache->putMany([
            'a' => 1,
            ['cacheKey' => 'b', 'cacheData' => 2],
        ]);

        $this->assertSame(1, $this->cache->getCache('a'));
        $this->assertSame(2, $this->cache->getCache('b'));
    }

    public function testSimpleFormWithinNamespace(): void
    {
        $this->cache->putMany(['x' => 1, 'y' => 2], 'orders');
        $this->assertSame(1, $this->cache->getCache('x', 'orders'));
        $this->assertSame(2, $this->cache->getCache('y', 'orders'));
    }

    public function testSimpleFormStoresArrayValues(): void
    {
        $this->cache->putMany([
            'user.1' => ['name' => 'Alice'],
            'user.2' => ['name' => 'Bob'],
        ]);

        $this->assertEquals(['name' => 'Alice'], $this->cache->getCache('user.1'));
        $this->assertEquals(['name' => 'Bob'], $this->cache->getCache('user.2'));
    }
}
