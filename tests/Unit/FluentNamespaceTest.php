<?php

use PHPUnit\Framework\TestCase;
use Silviooosilva\CacheerPhp\Cacheer;
use Silviooosilva\CacheerPhp\Support\PendingCache;

/**
 * Verifies the fluent namespace context exposed by Cacheer.
 *
 * Covers:
 *   - Cacheer::in() / namespace() / withoutNamespace() entry points.
 *   - Immutability of the returned PendingCache wrapper (chain methods must
 *     return a fresh instance and never mutate the underlying Cacheer).
 *   - Dot-notation namespace parsing — the flat `users.123` form must equal
 *     the chained `in('users')->in('123')` form.
 *   - Read/write delegation through PendingCache (get, put, has, missing,
 *     forget, pull, remember, rememberForever).
 *   - Namespace canonicalisation (leading/trailing dots are trimmed).
 */
class FluentNamespaceTest extends TestCase
{
    private Cacheer $cache;

    protected function setUp(): void
    {
        $this->cache = new Cacheer();
        $this->cache->setDriver()->useArrayDriver();
    }

    public function testInReturnsPendingCache(): void
    {
        $this->assertInstanceOf(PendingCache::class, $this->cache->in('users'));
    }

    public function testNamespaceAliasMatchesIn(): void
    {
        $this->assertSame(
            'users',
            $this->cache->namespace('users')->getNamespace(),
        );
    }

    public function testWithoutNamespaceReturnsEmptyContext(): void
    {
        $this->assertSame('', $this->cache->withoutNamespace()->getNamespace());
    }

    public function testPutAndGetWithinNamespace(): void
    {
        $this->cache->in('users')->put('123', ['name' => 'Alice']);
        $this->assertEquals(['name' => 'Alice'], $this->cache->in('users')->get('123'));
    }

    public function testNamespaceIsolation(): void
    {
        $this->cache->in('users')->put('1', 'alice');
        $this->cache->in('admins')->put('1', 'bob');

        $this->assertEquals('alice', $this->cache->in('users')->get('1'));
        $this->assertEquals('bob', $this->cache->in('admins')->get('1'));
    }

    public function testDotNotationPassedAsSingleString(): void
    {
        $this->cache->in('users.123')->put('profile', ['age' => 30]);
        $this->assertEquals(['age' => 30], $this->cache->in('users.123')->get('profile'));
    }

    public function testDotNotationViaChaining(): void
    {
        $pending = $this->cache->in('users')->in('123');
        $this->assertSame('users.123', $pending->getNamespace());

        $pending->put('profile', ['age' => 30]);
        $this->assertEquals(['age' => 30], $this->cache->in('users.123')->get('profile'));
    }

    public function testDotNotationChainEqualsFlatString(): void
    {
        $this->cache->in('users')->in('123')->put('p', 'chained');
        $this->assertSame('chained', $this->cache->in('users.123')->get('p'));
    }

    public function testPendingCacheIsImmutable(): void
    {
        $a = $this->cache->in('first');
        $b = $a->in('second');

        $this->assertNotSame($a, $b);
        $this->assertSame('first', $a->getNamespace());
        $this->assertSame('first.second', $b->getNamespace());
    }

    public function testWithoutNamespaceClearsBoundNamespace(): void
    {
        $a = $this->cache->in('tenant-a');
        $b = $a->withoutNamespace();

        $this->assertSame('tenant-a', $a->getNamespace());
        $this->assertSame('', $b->getNamespace());

        $a->put('k', 'tenant-val');
        $b->put('k', 'global-val');

        $this->assertSame('tenant-val', $this->cache->getCache('k', 'tenant-a'));
        $this->assertSame('global-val', $this->cache->getCache('k'));
    }

    public function testForgetAndPullAndMissingThroughPendingCache(): void
    {
        $pending = $this->cache->in('orders');
        $pending->put('o-1', 'pending');

        $this->assertTrue($pending->has('o-1'));
        $this->assertFalse($pending->missing('o-1'));

        $pulled = $pending->pull('o-1');
        $this->assertSame('pending', $pulled);
        $this->assertTrue($pending->missing('o-1'));

        $pending->put('o-2', 'shipped');
        $this->assertTrue($pending->forget('o-2'));
        $this->assertTrue($pending->missing('o-2'));
    }

    public function testRememberWithinNamespace(): void
    {
        $value = $this->cache->in('reports')->remember('latest', 60, fn () => 'computed');
        $this->assertSame('computed', $value);

        // Second call must hit cache.
        $value = $this->cache->in('reports')->remember('latest', 60, fn () => 'should-not-run');
        $this->assertSame('computed', $value);
    }

    public function testRememberForeverWithinNamespace(): void
    {
        $value = $this->cache->in('reports')->rememberForever('snapshot', fn () => 42);
        $this->assertSame(42, $value);
        $this->assertSame(42, $this->cache->in('reports')->get('snapshot'));
    }

    public function testCanonicaliseTrimsLeadingTrailingDots(): void
    {
        $this->assertSame('users', $this->cache->in('.users.')->getNamespace());
    }
}
