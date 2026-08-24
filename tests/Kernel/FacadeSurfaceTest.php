<?php

declare(strict_types=1);

namespace Tests\Kernel;

use PHPUnit\Framework\TestCase;
use Silviooosilva\CacheerPhp\Cacheer;
use Silviooosilva\CacheerPhp\Config\CachePolicy;
use Silviooosilva\CacheerPhp\Contracts\AtomicStore;
use Silviooosilva\CacheerPhp\Contracts\LockingStore;
use Silviooosilva\CacheerPhp\Exceptions\UnsupportedCapabilityException;
use Silviooosilva\CacheerPhp\Stores\ArrayStore;
use Tests\Support\FakeClock;
use Tests\Support\MinimalStore;

/**
 * Capabilities are reachable on the cache, with the scope applied.
 *
 * Previously they lived only on the store, so using one meant holding the raw
 * store and building a Key by hand — which silently ignored the scope and wrote
 * to a different entry than the caller's reads.
 */
final class FacadeSurfaceTest extends TestCase
{
    private FakeClock $clock;

    private Cacheer $cache;

    protected function setUp(): void
    {
        $this->clock = new FakeClock();
        $this->cache = new Cacheer(new ArrayStore($this->clock), $this->clock);
    }

    public function testCapabilityWritesLandInTheSameScopeAsReads(): void
    {
        $billing = $this->cache->in('billing');

        $billing->set('hits', 5);
        self::assertSame(6, $billing->increment('hits'));
        self::assertSame(6, $billing->get('hits'));

        // The root keyspace is untouched — this is the divergence that used to
        // happen silently when incrementing through the store.
        self::assertNull($this->cache->get('hits'));
        self::assertNull($this->cache->in('other')->get('hits'));
    }

    public function testIncrementAndDecrement(): void
    {
        self::assertSame(1, $this->cache->increment('views', 1, initial: 0));
        self::assertSame(11, $this->cache->increment('views', 10));
        self::assertSame(8, $this->cache->decrement('views', 3));
        self::assertSame(95, $this->cache->decrement('stock', 5, initial: 100));
    }

    public function testTouchExtendsLifetimeWithoutRewritingTheValue(): void
    {
        $this->cache->set('profile', ['id' => 1], ttl: 300);

        self::assertTrue($this->cache->touch('profile', 3600));
        $this->clock->advance(600);
        self::assertSame(['id' => 1], $this->cache->get('profile'));

        self::assertFalse($this->cache->touch('absent', 60));
    }

    public function testTagsAndLocksAreNamespacedByScope(): void
    {
        $this->cache->in('a')->set('p1', 'A');
        $this->cache->in('a')->tag('p1', 'products');
        $this->cache->in('b')->set('p1', 'B');
        $this->cache->in('b')->tag('p1', 'products');

        self::assertSame(1, $this->cache->in('a')->flushTag('products'));
        self::assertNull($this->cache->in('a')->get('p1'));
        self::assertSame('B', $this->cache->in('b')->get('p1'), 'another scope\'s tag must not be flushed');

        // Two scopes asking for "import" must not contend on the same mutex.
        $first = $this->cache->in('a')->lock('import', 30);
        $second = $this->cache->in('b')->lock('import', 30);

        self::assertTrue($first->acquire());
        self::assertTrue($second->acquire());
        $first->release();
        $second->release();
    }

    public function testEntriesAndPruneAreScoped(): void
    {
        $this->cache->set('root-key', 1);
        $this->cache->in('reports')->set('daily', 2);
        $this->cache->in('reports')->set('weekly', 3, ttl: 60);

        $names = array_map(
            static fn ($entry): string => $entry->key()->value(),
            iterator_to_array($this->cache->in('reports')->entries(), false),
        );
        sort($names);
        self::assertSame(['daily', 'weekly'], $names);

        $this->clock->advance(120);
        self::assertSame(1, $this->cache->prune());
    }

    public function testUnsupportedCapabilityNamesTheCapabilityAndOperation(): void
    {
        $cache = new Cacheer(new MinimalStore($this->clock), $this->clock);

        self::assertFalse($cache->supports(AtomicStore::class));
        self::assertFalse($cache->supports(LockingStore::class));

        $this->expectException(UnsupportedCapabilityException::class);
        $this->expectExceptionMessageMatches('/AtomicStore.+increment/');
        $cache->increment('n');
    }

    // ------------------------------------------------------- v5 ergonomics --

    public function testForeverAndRememberForeverDoNotExpire(): void
    {
        $this->cache->forever('config', ['theme' => 'dark']);
        self::assertSame('computed', $this->cache->rememberForever('rf', fn (): string => 'computed'));

        $this->clock->advance(86_400 * 365);

        self::assertSame(['theme' => 'dark'], $this->cache->get('config'));
        self::assertSame('computed', $this->cache->get('rf'));
    }

    public function testMissingIsTheInverseOfHas(): void
    {
        $this->cache->set('present', 1);

        self::assertFalse($this->cache->missing('present'));
        self::assertTrue($this->cache->missing('absent'));
    }

    public function testAddStoresOnlyWhenAbsent(): void
    {
        self::assertTrue($this->cache->add('once', 'first'));
        self::assertFalse($this->cache->add('once', 'second'));
        self::assertSame('first', $this->cache->get('once'));

        // A falsy stored value is still a value, so add() must not overwrite it.
        $this->cache->set('flag', false);
        self::assertFalse($this->cache->add('flag', true));
        self::assertFalse($this->cache->get('flag'));
    }

    public function testAddDegradesWhenTheStoreCannotLock(): void
    {
        $cache = new Cacheer(new MinimalStore($this->clock), $this->clock);

        self::assertTrue($cache->add('once', 'first'));
        self::assertFalse($cache->add('once', 'second'));
        self::assertSame('first', $cache->get('once'));
    }

    public function testPullReadsAndRemoves(): void
    {
        $this->cache->set('token', 'abc');

        self::assertSame('abc', $this->cache->pull('token'));
        self::assertFalse($this->cache->has('token'));
        self::assertSame('fallback', $this->cache->pull('token', 'fallback'));
    }

    public function testPullDistinguishesAStoredNullFromAMiss(): void
    {
        $this->cache->set('nullable', null);

        self::assertNull($this->cache->pull('nullable', 'default'));
        self::assertSame('default', $this->cache->pull('nullable', 'default'));
    }

    public function testInIsAnAliasOfScope(): void
    {
        $this->cache->in('billing')->set('k', 'v');

        self::assertSame('v', $this->cache->scope('billing')->get('k'));
        self::assertSame('billing', (string) $this->cache->in('billing')->boundScope());
    }

    public function testStatsDescribeTheCacheWithoutLeakingValues(): void
    {
        $stats = $this->cache->in('reports')->withPolicy(CachePolicy::defaults())->stats();

        self::assertSame('ArrayStore', $stats['store']);
        self::assertSame('reports', $stats['scope']);
        self::assertTrue($stats['policy']);
        self::assertTrue($stats['capabilities']['atomic']);
        self::assertFalse($this->cache->stats()['policy']);
    }

    /**
     * flexible()'s stale window is an explicit argument, not a default a policy
     * gets to reshape — jitter or negative caching must not shorten it.
     */
    public function testPolicyDoesNotReshapeTheFlexibleStaleWindow(): void
    {
        $cache = $this->cache->withPolicy(
            CachePolicy::defaults()
                ->withTtl(5)
                ->withJitter(1.0, static fn (): float => 0.0)   // would halve any TTL
                ->withNegativeTtl(1),
        );

        $cache->flexible('feed', 30, 300, fn (): int => 1);

        // Deep inside the stale window, the entry must still be readable.
        $this->clock->advance(299);
        self::assertSame(1, $cache->get('feed'));
    }

    public function testPolicySurvivesScopingInBothOrders(): void
    {
        $policy = CachePolicy::defaults()->withTtl(60)->withJitter(0.0);

        foreach ([
            $this->cache->withPolicy($policy)->in('a'),
            $this->cache->in('a')->withPolicy($policy),
        ] as $index => $cache) {
            $cache->set("k{$index}", 'v');           // no explicit TTL → policy's 60s
            self::assertSame('v', $cache->get("k{$index}"));
        }

        $this->clock->advance(61);

        self::assertNull($this->cache->in('a')->get('k0'));
        self::assertNull($this->cache->in('a')->get('k1'));
    }
}
