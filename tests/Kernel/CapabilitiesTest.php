<?php

declare(strict_types=1);

namespace Tests\Kernel;

use PHPUnit\Framework\TestCase;
use Silviooosilva\CacheerPhp\Cacheer;
use Silviooosilva\CacheerPhp\Contracts\AtomicStore;
use Silviooosilva\CacheerPhp\Contracts\BatchStore;
use Silviooosilva\CacheerPhp\Contracts\LockingStore;
use Silviooosilva\CacheerPhp\Contracts\TaggableStore;
use Silviooosilva\CacheerPhp\Kernel\Capabilities;
use Silviooosilva\CacheerPhp\Observability\NullEventDispatcher;
use Silviooosilva\CacheerPhp\Stores\ArrayStore;
use Silviooosilva\CacheerPhp\Stores\InstrumentedStore;
use Silviooosilva\CacheerPhp\Stores\ResilientStore;
use Silviooosilva\CacheerPhp\Stores\TieredStore;
use Tests\Support\FakeClock;
use Tests\Support\MinimalStore;

/**
 * A decorator cannot conditionally implement an interface, so `instanceof` is
 * not a usable answer to "can this store do X?". Capabilities is, and the kernel
 * must route every optional code path through it.
 */
final class CapabilitiesTest extends TestCase
{
    public function testPlainStoreAnswersByInstanceof(): void
    {
        $clock = new FakeClock();

        self::assertTrue(Capabilities::supports(new ArrayStore($clock), LockingStore::class));
        self::assertFalse(Capabilities::supports(new MinimalStore($clock), LockingStore::class));
    }

    public function testDecoratorsReportTheWrappedStoresCapabilities(): void
    {
        $clock = new FakeClock();
        $minimal = new MinimalStore($clock);
        $full = new ArrayStore($clock);

        $instrumented = new InstrumentedStore($minimal, new NullEventDispatcher());
        self::assertInstanceOf(LockingStore::class, $instrumented, 'the interface is declared…');
        self::assertFalse(Capabilities::supports($instrumented, LockingStore::class), '…but not honored');
        self::assertFalse(Capabilities::supports($instrumented, AtomicStore::class));

        // Batching is implemented by the decorator over the four core methods.
        self::assertTrue(Capabilities::supports($instrumented, BatchStore::class));

        self::assertTrue(Capabilities::supports(
            new InstrumentedStore($full, new NullEventDispatcher()),
            LockingStore::class,
        ));
    }

    public function testTieredDefersToL2AndResilientNeedsBoth(): void
    {
        $clock = new FakeClock();
        $minimal = new MinimalStore($clock);
        $full = new ArrayStore($clock);

        // L2 is the source of truth for shared capabilities.
        self::assertFalse(Capabilities::supports(new TieredStore($full, $minimal, $clock), TaggableStore::class));
        self::assertTrue(Capabilities::supports(new TieredStore($minimal, $full, $clock), TaggableStore::class));

        // Writes always reach the fallback, so both stores must honor it.
        self::assertFalse(Capabilities::supports(new ResilientStore($full, $minimal, null, $clock), TaggableStore::class));
        self::assertTrue(Capabilities::supports(new ResilientStore($full, new ArrayStore($clock), null, $clock), TaggableStore::class));
    }

    public function testNestedDecoratorsStayHonest(): void
    {
        $clock = new FakeClock();
        $nested = new InstrumentedStore(
            new TieredStore(new ArrayStore($clock), new MinimalStore($clock), $clock),
            new NullEventDispatcher(),
        );

        self::assertFalse(Capabilities::supports($nested, LockingStore::class));
    }

    /**
     * The regression this whole mechanism exists for: remember() single-flights
     * through a lock when one is available and must degrade to a plain compute
     * when it is not. Wrapping a store must never change that answer.
     */
    public function testRememberSurvivesBeingWrappedInADecorator(): void
    {
        $clock = new FakeClock();
        $minimal = new MinimalStore($clock);

        $bare = new Cacheer($minimal, $clock);
        self::assertSame('computed', $bare->remember('k', 60, fn (): string => 'computed'));

        $wrapped = Cacheer::instrumented($minimal, new NullEventDispatcher(), clock: $clock);
        self::assertSame('computed', $wrapped->remember('k2', 60, fn (): string => 'computed'));

        $tiered = new Cacheer(new TieredStore(new ArrayStore($clock), $minimal, $clock), $clock);
        self::assertSame('computed', $tiered->remember('k3', 60, fn (): string => 'computed'));
    }

    public function testFlexibleSurvivesBeingWrappedInADecorator(): void
    {
        $clock = new FakeClock();
        $cache = Cacheer::instrumented(new MinimalStore($clock), new NullEventDispatcher(), clock: $clock);

        self::assertSame(1, $cache->flexible('feed', 30, 300, fn (): int => 1));

        // Past the fresh window: serve stale, refresh through the deferred path.
        $clock->advance(60);
        self::assertSame(1, $cache->flexible('feed', 30, 300, fn (): int => 2));
        self::assertSame(2, $cache->get('feed'));
    }
}
