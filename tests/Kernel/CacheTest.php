<?php

declare(strict_types=1);

namespace Tests\Kernel;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Silviooosilva\CacheerPhp\Cacheer;
use Silviooosilva\CacheerPhp\Contracts\Store;
use Silviooosilva\CacheerPhp\Exceptions\StoreOperationFailedException;
use Silviooosilva\CacheerPhp\Exceptions\UnsupportedCapabilityException;
use Silviooosilva\CacheerPhp\Kernel\CacheEntry;
use Silviooosilva\CacheerPhp\Kernel\Key;
use Silviooosilva\CacheerPhp\Kernel\ScopedCacheer;
use Silviooosilva\CacheerPhp\Kernel\Ttl;
use Silviooosilva\CacheerPhp\Stores\ArrayStore;
use Tests\Support\FakeClock;

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

        self::assertInstanceOf(ScopedCacheer::class, $tenant);
        self::assertSame('tenant', (string) $tenant->name());
        self::assertSame('tenant/users', (string) $users->name());

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

    public function testCoreHasNoMagicDelegationOrStaticState(): void
    {
        $cache = new \ReflectionClass(Cacheer::class);
        $scoped = new \ReflectionClass(ScopedCacheer::class);

        self::assertFalse($cache->hasMethod('__call'));
        self::assertFalse($cache->hasMethod('__callStatic'));
        self::assertSame([], array_filter(
            $cache->getProperties(),
            static fn (\ReflectionProperty $property): bool => $property->isStatic(),
        ));
        self::assertTrue($scoped->isReadOnly());
    }
}
