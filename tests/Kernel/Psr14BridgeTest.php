<?php

declare(strict_types=1);

namespace Tests\Kernel;

use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Silviooosilva\CacheerPhp\Cacheer;
use Silviooosilva\CacheerPhp\Observability\CacheEvent;
use Silviooosilva\CacheerPhp\Observability\CacheEventType;
use Silviooosilva\CacheerPhp\Observability\Psr14EventDispatcher;
use Silviooosilva\CacheerPhp\Stores\ArrayStore;
use Tests\Support\FakeClock;

/**
 * Cache events must reach an application's existing PSR-14 wiring unchanged.
 */
final class Psr14BridgeTest extends TestCase
{
    public function testCacheEventsReachAPsr14Dispatcher(): void
    {
        $received = [];
        $psr = new class ($received) implements EventDispatcherInterface {
            /**
             * @param list<object> $received
             */
            public function __construct(private array &$received)
            {
            }

            public function dispatch(object $event): object
            {
                $this->received[] = $event;

                return $event;
            }
        };

        $clock = new FakeClock();
        $cache = Cacheer::instrumented(new ArrayStore($clock), new Psr14EventDispatcher($psr), clock: $clock);

        $cache->set('k', 'v');
        $cache->get('k');
        $cache->get('absent');

        $types = array_map(static fn (CacheEvent $event): CacheEventType => $event->type, $received);

        self::assertSame(
            [CacheEventType::Write, CacheEventType::Hit, CacheEventType::Miss],
            $types,
        );
    }
}
