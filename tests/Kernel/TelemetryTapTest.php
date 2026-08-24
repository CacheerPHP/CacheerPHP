<?php

declare(strict_types=1);

namespace Tests\Kernel;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Silviooosilva\CacheerPhp\Cacheer;
use Silviooosilva\CacheerPhp\Config\CachePolicy;
use Silviooosilva\CacheerPhp\Contracts\Store;
use Silviooosilva\CacheerPhp\Observability\CacheEvent;
use Silviooosilva\CacheerPhp\Observability\EventBus;
use Silviooosilva\CacheerPhp\Observability\Telemetry;
use Silviooosilva\CacheerPhp\Stores\ArrayStore;
use Silviooosilva\CacheerPhp\Stores\InstrumentedStore;
use Tests\Support\FakeClock;

/**
 * The global telemetry tap is how a telemetry package (cacheerphp/monitor)
 * observes caches it did not construct, with no wiring in user code.
 *
 * v5 hooked Cacheer::__call, so every instance reported however it was built.
 * The tap must match that: if it only covered some construction paths, a user
 * doing `new Cacheer($store)` or `Cacheer::tiered(...)` would silently see
 * nothing on their dashboard.
 */
final class TelemetryTapTest extends TestCase
{
    protected function setUp(): void
    {
        Telemetry::reset();
    }

    protected function tearDown(): void
    {
        Telemetry::reset();
    }

    /**
     * @return array<string, array{callable(FakeClock): Cacheer}>
     */
    public static function constructionPaths(): array
    {
        return [
            'inMemory()'    => [fn (FakeClock $c): Cacheer => Cacheer::inMemory($c)],
            'build()'       => [fn (FakeClock $c): Cacheer => Cacheer::build()->inMemory()->clock($c)->create()],
            'new Cacheer()' => [fn (FakeClock $c): Cacheer => new Cacheer(new ArrayStore($c), $c)],
            'tiered()'      => [fn (FakeClock $c): Cacheer => Cacheer::tiered(new ArrayStore($c), new ArrayStore($c), clock: $c)],
            'resilient()'   => [fn (FakeClock $c): Cacheer => Cacheer::resilient(new ArrayStore($c), new ArrayStore($c), clock: $c)],
            'scope()'       => [fn (FakeClock $c): Cacheer => Cacheer::inMemory($c)->scope('s')],
            'withPolicy()'  => [fn (FakeClock $c): Cacheer => Cacheer::inMemory($c)->withPolicy(CachePolicy::defaults())],
        ];
    }

    /**
     * @param callable(FakeClock): Cacheer $build
     */
    #[DataProvider('constructionPaths')]
    public function testEveryConstructionPathReportsToTheTap(callable $build): void
    {
        $events = [];
        Telemetry::listen(function (CacheEvent $event) use (&$events): void {
            $events[] = $event->type->value;
        });

        $cache = $build(new FakeClock());
        $cache->set('k', 'v');
        $cache->get('k');

        self::assertSame(['cache.write', 'cache.hit'], $events);
    }

    /**
     * @param callable(FakeClock): Cacheer $build
     */
    #[DataProvider('constructionPaths')]
    public function testNoListenersMeansNoInstrumentationAtAll(callable $build): void
    {
        $cache = $build(new FakeClock());

        self::assertNotInstanceOf(
            InstrumentedStore::class,
            $cache->store(),
            'With no listeners the tap must be a complete no-op — no wrapper, no overhead.',
        );
    }

    public function testEachOperationIsReportedExactlyOnce(): void
    {
        $count = 0;
        Telemetry::listen(function () use (&$count): void {
            $count++;
        });

        $cache = Cacheer::inMemory(new FakeClock());
        $cache->set('k', 'v');

        self::assertSame(1, $count, 'A double-wrapped store would report twice.');
    }

    public function testExplicitDispatcherOptsOutOfTheTap(): void
    {
        $tapped = 0;
        Telemetry::listen(function () use (&$tapped): void {
            $tapped++;
        });

        $own = 0;
        $bus = new EventBus();
        $bus->listen(function () use (&$own): void {
            $own++;
        });

        $clock = new FakeClock();
        $cache = new Cacheer(new ArrayStore($clock), $clock, null, $bus);
        $cache->set('k', 'v');

        self::assertSame(0, $tapped, 'Wiring your own dispatcher means you own observability.');
        self::assertNotInstanceOf(InstrumentedStore::class, $cache->store());
        self::assertSame(0, $own, 'A bare store with an explicit dispatcher is still not instrumented.');
    }

    public function testInstrumentedConstructorIsNeverDoubleWrapped(): void
    {
        Telemetry::listen(static fn (): null => null);

        $clock = new FakeClock();
        $bus = new EventBus();
        $cache = Cacheer::instrumented(new ArrayStore($clock), $bus, clock: $clock);

        $store = $cache->store();
        self::assertInstanceOf(InstrumentedStore::class, $store);

        $inner = (new ReflectionProperty($store, 'inner'))->getValue($store);
        self::assertNotInstanceOf(InstrumentedStore::class, $inner);
        self::assertInstanceOf(Store::class, $inner);
    }

    /**
     * Capability operations are writes too. v5 dispatched on every method, so a
     * dashboard saw counters and tag flushes; if the tap skipped them, promoting
     * them onto the facade would have made them invisible.
     */
    public function testCapabilityMutationsAreReported(): void
    {
        $events = [];
        Telemetry::listen(function (CacheEvent $event) use (&$events): void {
            $events[] = [$event->type->value, $event->key, $event->count];
        });

        $cache = Cacheer::inMemory(new FakeClock());
        $cache->in('billing')->increment('invoices', 1, initial: 0);
        $cache->set('p1', 'x');
        $cache->tag('p1', 'products');
        $cache->touch('p1', 3600);
        $cache->flushTag('products');

        self::assertSame([
            ['cache.write', 'billing/invoices', 1],  // counter, scope applied, new value
            ['cache.write', 'p1', null],             // set
            ['cache.write', 'p1', 1],                // tag (one tag added)
            ['cache.write', 'p1', null],             // touch
            ['cache.prune', null, 1],                // tag flush, one entry removed
        ], $events);
    }

    public function testATouchThatMissesIsNotReportedAsAWrite(): void
    {
        $events = [];
        Telemetry::listen(function (CacheEvent $event) use (&$events): void {
            $events[] = $event->type->value;
        });

        $cache = Cacheer::inMemory(new FakeClock());
        self::assertFalse($cache->touch('never-written', 60));

        self::assertSame([], $events, 'Nothing changed, so nothing should be reported.');
    }

    public function testCaptureValuesIsHonoredAndOffByDefault(): void
    {
        $captured = [];
        Telemetry::listen(function (CacheEvent $event) use (&$captured): void {
            $captured[] = $event->hasValue;
        });

        $cache = Cacheer::inMemory(new FakeClock());
        $cache->set('k', 'v');
        self::assertSame([false], $captured, 'Values must never leave the process by default.');

        Telemetry::reset();
        $captured = [];
        Telemetry::captureValues(true);
        Telemetry::listen(function (CacheEvent $event) use (&$captured): void {
            $captured[] = $event->hasValue;
        });

        $cache = Cacheer::inMemory(new FakeClock());
        $cache->set('k', 'v');
        self::assertSame([true], $captured);
    }
}
