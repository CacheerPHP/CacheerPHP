<?php

declare(strict_types=1);

namespace Tests\Kernel;

use Closure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Silviooosilva\CacheerPhp\Cacheer;
use Silviooosilva\CacheerPhp\Config\CachePolicy;
use Silviooosilva\CacheerPhp\Contracts\Store;
use Silviooosilva\CacheerPhp\Kernel\Key;
use Silviooosilva\CacheerPhp\Kernel\Scope;
use Silviooosilva\CacheerPhp\Stores\ArrayStore;
use Silviooosilva\CacheerPhp\Support\AfterResponseDeferredExecutor;
use Tests\Support\FakeClock;
use Tests\Support\MinimalStore;

/**
 * Compound operations (remember, add, pull, flexible, stale-on-error) read and
 * write through internal helpers. The scope must be applied exactly once on
 * that path, or a scoped view reads one key and writes another.
 */
final class ScopedCompoundOperationsTest extends TestCase
{
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
