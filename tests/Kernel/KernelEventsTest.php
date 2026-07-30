<?php

declare(strict_types=1);

namespace Tests\Kernel;

use PHPUnit\Framework\TestCase;
use Silviooosilva\CacheerPhp\Cacheer;
use Silviooosilva\CacheerPhp\Observability\CacheEvent;
use Silviooosilva\CacheerPhp\Observability\CacheEventType;
use Silviooosilva\CacheerPhp\Observability\EventBus;
use Silviooosilva\CacheerPhp\Stores\ArrayStore;
use Tests\Support\FakeClock;

/**
 * The promotion / stale-served / refresh events that Milestone 5 deferred are
 * emitted by the kernel through the shared dispatcher.
 */
final class KernelEventsTest extends TestCase
{
    private FakeClock $clock;

    private EventBus $bus;

    /**
     * @var list<CacheEvent>
     */
    private array $seen = [];

    protected function setUp(): void
    {
        $this->clock = new FakeClock();
        $this->bus = new EventBus();
        $this->bus->listen(function (CacheEvent $event): void {
            $this->seen[] = $event;
        });
    }

    /**
     * @return list<CacheEventType>
     */
    private function types(): array
    {
        return array_map(static fn (CacheEvent $e): CacheEventType => $e->type, $this->seen);
    }

    public function testTieredPromotionEmitsAPromotionEvent(): void
    {
        $l2 = new ArrayStore($this->clock);
        $cache = Cacheer::tiered(new ArrayStore($this->clock), $l2, clock: $this->clock, events: $this->bus);

        $cache->set('k', 'v');
        $this->seen = [];

        // Force an L1 miss so the next read promotes from L2.
        $cache->scope('__none__'); // no-op to keep the store warm
        $l2->set(\Silviooosilva\CacheerPhp\Kernel\Key::named('k2'), 'v2', \Silviooosilva\CacheerPhp\Kernel\Ttl::forever());
        $cache->get('k2');

        self::assertContains(CacheEventType::Promotion, $this->types());
    }

    public function testFlexibleEmitsStaleServedAndRefreshEvents(): void
    {
        $cache = new Cacheer(new ArrayStore($this->clock), $this->clock, events: $this->bus);

        $calls = 0;
        $factory = function () use (&$calls): string {
            $calls++;

            return 'value-' . $calls;
        };

        $cache->flexible('report', 30, 120, $factory); // fresh compute
        $this->seen = [];

        $this->clock->advance(40); // into the stale window
        $cache->flexible('report', 30, 120, $factory);

        self::assertContains(CacheEventType::StaleServed, $this->types());
        self::assertContains(CacheEventType::Refresh, $this->types(), 'The synchronous executor refreshes inline.');
    }
}
