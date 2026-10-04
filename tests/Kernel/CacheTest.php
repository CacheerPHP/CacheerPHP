<?php

declare(strict_types=1);

namespace Tests\Kernel;

use Closure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Silviooosilva\CacheerPhp\Cacheer;
use Silviooosilva\CacheerPhp\Config\CachePolicy;
use Silviooosilva\CacheerPhp\Contracts\Cache;
use Silviooosilva\CacheerPhp\Contracts\Store;
use Silviooosilva\CacheerPhp\Exceptions\StoreOperationFailedException;
use Silviooosilva\CacheerPhp\Exceptions\UnsupportedCapabilityException;
use Silviooosilva\CacheerPhp\Kernel\CacheEntry;
use Silviooosilva\CacheerPhp\Kernel\Key;
use Silviooosilva\CacheerPhp\Kernel\Scope;
use Silviooosilva\CacheerPhp\Kernel\Ttl;
use Silviooosilva\CacheerPhp\Stores\ArrayStore;
use Silviooosilva\CacheerPhp\Support\AfterResponseDeferredExecutor;
use Tests\Support\FakeClock;
use Tests\Support\MinimalStore;

final class CacheTest extends TestCase
{
    private FakeClock $clock;

    private ArrayStore $store;

    private Cacheer $cache;

    protected function setUp(): void
    {
        $this->clock = new FakeClock();
        $this->store = new ArrayStore($this->clock);
        $this->cache = new Cacheer($this->store);
    }

    public function testExplicitCoreApiCoversTheCommonCacheWorkflow(): void
    {
        self::assertSame('default', $this->cache->get('missing', 'default'));

        $this->cache->set('user:42', ['name' => 'Ada'], '10 minutes');
        self::assertTrue($this->cache->has('user:42'));
        self::assertSame(['name' => 'Ada'], $this->cache->get('user:42'));
        self::assertTrue($this->cache->delete('user:42'));
        self::assertFalse($this->cache->has('user:42'));

        $this->cache->set('clear-me', true);
        $this->cache->clear();
        self::assertFalse($this->cache->has('clear-me'));
    }

    public function testRememberTreatsCachedNullAsAHit(): void
    {
        $calls = 0;

        $first = $this->cache->remember('nullable', 60, function () use (&$calls): mixed {
            $calls++;

            return null;
        });
        $second = $this->cache->remember('nullable', 60, function () use (&$calls): string {
            $calls++;

            return 'wrong';
        });

        self::assertNull($first);
        self::assertNull($second);
        self::assertSame(1, $calls);
        self::assertTrue($this->cache->entry('nullable')->isHit());
    }

    public function testBatchApiUsesNativeCapabilityAndRepresentsMissesWithDefaults(): void
    {
        $this->cache->setMany(['one' => 1, 'nullable' => null], Ttl::forever());

        self::assertSame(
            ['nullable' => null, 'missing' => 'fallback', 'one' => 1],
            $this->cache->many(['nullable', 'missing', 'one'], 'fallback'),
        );
        self::assertTrue($this->cache->deleteMany(['one', 'nullable']));
        self::assertFalse($this->cache->has('one'));
    }

    public function testScopesAreImmutableNestedAndIsolated(): void
    {
        $tenant = $this->cache->scope('tenant');
        $users = $tenant->scope('users');

        // A scoped cache is the same type as an unscoped one, so it keeps the
        // whole surface — that is what makes scope × policy × capability compose.
        self::assertInstanceOf(Cacheer::class, $tenant);
        self::assertInstanceOf(Cache::class, $tenant);
        self::assertSame('tenant', (string) $tenant->boundScope());
        self::assertSame('tenant/users', (string) $users->boundScope());

        $this->cache->set('same', 'root');
        $tenant->set('same', 'tenant');
        $users->set('same', 'users');

        self::assertSame('root', $this->cache->get('same'));
        self::assertSame('tenant', $tenant->get('same'));
        self::assertSame('users', $users->get('same'));

        $tenant->clear();

        self::assertSame('root', $this->cache->get('same'));
        self::assertFalse($tenant->has('same'));
        self::assertFalse($users->has('same'));
    }

    public function testCoreFallsBackWhenBatchCapabilityIsAbsent(): void
    {
        $store = new class () implements Store {
            /**
             * @var array<string, CacheEntry>
             */
            private array $items = [];

            public function get(Key $key): CacheEntry
            {
                return $this->items[$key->identity()] ?? CacheEntry::miss($key);
            }

            public function set(Key $key, mixed $value, Ttl $ttl): void
            {
                $this->items[$key->identity()] = CacheEntry::hit($key, $value, 1, null);
            }

            public function delete(Key $key): bool
            {
                $exists = isset($this->items[$key->identity()]);
                unset($this->items[$key->identity()]);

                return $exists;
            }

            public function clear(): void
            {
                $this->items = [];
            }
        };
        $cache = new Cacheer($store);

        $cache->setMany(['a' => 1, 'b' => 2]);

        self::assertSame(['b' => 2, 'a' => 1], $cache->many(['b', 'a']));
        self::assertTrue($cache->deleteMany(['a', 'b']));
    }

    public function testScopedClearRequiresAnExplicitCapability(): void
    {
        $store = new class () implements Store {
            public function get(Key $key): CacheEntry
            {
                return CacheEntry::miss($key);
            }

            public function set(Key $key, mixed $value, Ttl $ttl): void
            {
            }

            public function delete(Key $key): bool
            {
                return false;
            }

            public function clear(): void
            {
            }
        };

        $this->expectException(UnsupportedCapabilityException::class);
        (new Cacheer($store))->scope('tenant')->clear();
    }

    public function testStoreFailuresRetainTheirOriginalException(): void
    {
        $previous = new RuntimeException('backend unavailable');
        $store = new class ($previous) implements Store {
            public function __construct(private readonly RuntimeException $failure)
            {
            }

            public function get(Key $key): CacheEntry
            {
                throw $this->failure;
            }

            public function set(Key $key, mixed $value, Ttl $ttl): void
            {
            }

            public function delete(Key $key): bool
            {
                return false;
            }

            public function clear(): void
            {
            }
        };

        try {
            (new Cacheer($store))->get('key');
            self::fail('Expected the store failure to be wrapped.');
        } catch (StoreOperationFailedException $exception) {
            self::assertSame('get', $exception->operation);
            self::assertSame($previous, $exception->getPrevious());
            self::assertSame('key', $exception->key?->value());
        }
    }

    public function testAddDoesNotReportSuccessWhenItsLockTimesOut(): void
    {
        $rival = $this->store->lock('cacheer:add:' . hash('sha256', Key::named('k')->identity()), Ttl::seconds(30));
        self::assertTrue($rival->acquire());

        try {
            $this->cache->add('k', 'value', 60);
            self::fail('add() must not succeed without holding its lock.');
        } catch (StoreOperationFailedException $exception) {
            self::assertSame('add', $exception->operation);
        }

        self::assertFalse($this->cache->has('k'), 'A timed-out add() must not write.');

        $rival->release();
        self::assertTrue($this->cache->add('k', 'value', 60));
    }

    public function testRememberRereadsAfterTimingOutOnAnotherWorkersLock(): void
    {
        $key = Key::named('k');
        $rival = $this->store->lock('cacheer:sf:' . hash('sha256', $key->identity()), Ttl::seconds(30));
        self::assertTrue($rival->acquire());

        // The lock holder stores its result while this caller waits, but keeps
        // the lock past the wait, so the caller times out.
        $this->clock->onSleep = function () use ($key): void {
            $this->store->set($key, 'from-other-worker', Ttl::seconds(60));
        };

        $calls = 0;
        $value = $this->cache->remember('k', 60, function () use (&$calls): string {
            $calls++;

            return 'recomputed';
        });

        self::assertSame('from-other-worker', $value);
        self::assertSame(0, $calls, 'A value stored during the wait must not be recomputed.');
    }

    public function testCoreHasNoMagicDelegationOrStaticState(): void
    {
        $cache = new \ReflectionClass(Cacheer::class);

        self::assertFalse($cache->hasMethod('__call'));
        self::assertFalse($cache->hasMethod('__callStatic'));
        self::assertSame([], array_filter(
            $cache->getProperties(),
            static fn (\ReflectionProperty $property): bool => $property->isStatic(),
        ));
        self::assertTrue($cache->isReadOnly());
    }

    /**
     * @return iterable<string, array{Closure(FakeClock): Store}>
     */
    public static function stores(): iterable
    {
        // ArrayStore can lock, so compound operations take the locked path;
        // MinimalStore cannot, so they take the unlocked one.
        yield 'locking store' => [static fn (FakeClock $clock): Store => new ArrayStore($clock)];
        yield 'non-locking store' => [static fn (FakeClock $clock): Store => new MinimalStore($clock)];
    }

    /**
     * @param Closure(FakeClock): Store $store
     */
    #[DataProvider('stores')]
    public function testTwoColdScopedRemembersComputeOnce(Closure $store): void
    {
        $clock = new FakeClock();
        $cache = (new Cacheer($store($clock), $clock))->scope('tenant');

        $calls = 0;
        $factory = function () use (&$calls): string {
            $calls++;

            return 'value';
        };

        self::assertSame('value', $cache->remember('k', 60, $factory));
        self::assertSame('value', $cache->remember('k', 60, $factory));
        self::assertSame(1, $calls);
        self::assertSame('value', $cache->get('k'));
    }

    /**
     * @param Closure(FakeClock): Store $store
     */
    #[DataProvider('stores')]
    public function testScopedRememberCachesNull(Closure $store): void
    {
        $clock = new FakeClock();
        $cache = (new Cacheer($store($clock), $clock))->scope('tenant')->scope('users');

        $calls = 0;
        $factory = function () use (&$calls): mixed {
            $calls++;

            return null;
        };

        self::assertNull($cache->remember('k', 60, $factory));
        self::assertNull($cache->rememberForever('k', $factory));
        self::assertSame(1, $calls, 'A cached null under a nested scope must still be a hit.');
        self::assertTrue($cache->has('k'));
    }

    /**
     * @param Closure(FakeClock): Store $store
     */
    #[DataProvider('stores')]
    public function testScopedRememberHonoursATypedScopedKey(Closure $store): void
    {
        $clock = new FakeClock();
        $root = new Cacheer($store($clock), $clock);
        $tenant = $root->scope('tenant');
        $key = Key::named('k')->within(Scope::named('inner'));

        $calls = 0;
        $factory = function () use (&$calls): int {
            return ++$calls;
        };

        self::assertSame(1, $tenant->remember($key, 60, $factory));
        self::assertSame(1, $tenant->remember($key, 60, $factory));
        self::assertSame(1, $calls);

        // The typed key's own scope nests under the view's scope, once.
        self::assertSame(1, $root->scope('tenant')->scope('inner')->get('k'));
        self::assertFalse($tenant->has('k'));
    }

    /**
     * @param Closure(FakeClock): Store $store
     */
    #[DataProvider('stores')]
    public function testScopedAddPreservesAnExistingEntry(Closure $store): void
    {
        $clock = new FakeClock();
        $cache = (new Cacheer($store($clock), $clock))->scope('tenant');

        self::assertTrue($cache->add('k', 'first', 60));
        self::assertFalse($cache->add('k', 'second', 60));
        self::assertSame('first', $cache->get('k'));

        $cache->set('null', null, 60);
        self::assertFalse($cache->add('null', 'replacement', 60), 'A cached null is an existing entry.');
        self::assertNull($cache->get('null', 'default'));
    }

    /**
     * @param Closure(FakeClock): Store $store
     */
    #[DataProvider('stores')]
    public function testScopedPullReturnsAndRemovesTheEntry(Closure $store): void
    {
        $clock = new FakeClock();
        $cache = (new Cacheer($store($clock), $clock))->scope('tenant')->scope('users');

        $cache->set('k', 'value', 60);
        $cache->set('null', null, 60);

        self::assertSame('value', $cache->pull('k', 'default'));
        self::assertFalse($cache->has('k'));
        self::assertSame('default', $cache->pull('k', 'default'));

        self::assertNull($cache->pull('null', 'default'));
        self::assertFalse($cache->has('null'));
    }

    /**
     * @param Closure(FakeClock): Store $store
     */
    #[DataProvider('stores')]
    public function testSiblingScopesRemainIndependent(Closure $store): void
    {
        $clock = new FakeClock();
        $root = new Cacheer($store($clock), $clock);
        $a = $root->scope('a');
        $b = $root->scope('b');

        self::assertSame('a', $a->remember('k', 60, fn (): string => 'a'));
        self::assertSame('b', $b->remember('k', 60, fn (): string => 'b'));

        self::assertTrue($b->add('only-b', 'b', 60));
        self::assertTrue($a->add('only-b', 'a', 60));

        self::assertSame('a', $a->pull('k'));
        self::assertSame('b', $b->get('k'));
        self::assertSame('b', $b->get('only-b'));
        self::assertFalse($root->has('k'));
    }

    /**
     * @param Closure(FakeClock): Store $store
     */
    #[DataProvider('stores')]
    public function testScopedFlexibleServesFreshAndRefreshesTheSameKey(Closure $store): void
    {
        $clock = new FakeClock();
        $executor = new AfterResponseDeferredExecutor();
        $cache = (new Cacheer($store($clock), $clock, $executor))->scope('tenant');

        $calls = 0;
        $factory = function () use (&$calls): string {
            return 'value-' . ++$calls;
        };

        self::assertSame('value-1', $cache->flexible('k', 30, 120, $factory));
        $clock->advance(10);
        self::assertSame('value-1', $cache->flexible('k', 30, 120, $factory));
        self::assertSame(1, $calls, 'A fresh scoped value must not be recomputed.');

        $clock->advance(30);
        self::assertSame('value-1', $cache->flexible('k', 30, 120, $factory));
        $executor->flush();

        self::assertSame(2, $calls);
        self::assertSame('value-2', $cache->get('k'));
        self::assertSame('value-2', $cache->flexible('k', 30, 120, $factory));
    }

    /**
     * @param Closure(FakeClock): Store $store
     */
    #[DataProvider('stores')]
    public function testScopedServeStaleOnErrorUsesTheScopedEntry(Closure $store): void
    {
        $clock = new FakeClock();
        $cache = (new Cacheer($store($clock), $clock))
            ->scope('tenant')
            ->withPolicy(CachePolicy::defaults()->withServeStaleOnError(60));

        $calls = 0;
        $factory = function () use (&$calls): string {
            return 'value-' . ++$calls;
        };

        self::assertSame('value-1', $cache->remember('k', 100, $factory));
        self::assertSame('value-1', $cache->remember('k', 100, $factory));
        self::assertSame(1, $calls);

        $clock->advance(101);
        $served = $cache->remember('k', 100, function (): string {
            throw new RuntimeException('upstream is down');
        });

        self::assertSame('value-1', $served);
    }
}
