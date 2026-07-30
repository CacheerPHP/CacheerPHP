<?php

declare(strict_types=1);

namespace Tests\Kernel;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Silviooosilva\CacheerPhp\Cacheer;
use Silviooosilva\CacheerPhp\Stores\ArrayStore;
use Silviooosilva\CacheerPhp\Support\AfterResponseDeferredExecutor;
use Tests\Support\FakeClock;

final class RememberAndFlexibleTest extends TestCase
{
    private FakeClock $clock;

    private Cacheer $cache;

    protected function setUp(): void
    {
        $this->clock = new FakeClock();
        $this->cache = new Cacheer(new ArrayStore($this->clock), $this->clock);
    }

    public function testRememberComputesOnceAndCachesIncludingNull(): void
    {
        $calls = 0;
        $factory = function () use (&$calls): mixed {
            $calls++;

            return null;
        };

        self::assertNull($this->cache->remember('k', 60, $factory));
        self::assertNull($this->cache->remember('k', 60, $factory));
        self::assertSame(1, $calls, 'A cached null must not trigger recomputation.');
    }

    public function testFlexibleServesFreshWithoutRecomputing(): void
    {
        $calls = 0;
        $factory = function () use (&$calls): string {
            $calls++;

            return 'value-' . $calls;
        };

        self::assertSame('value-1', $this->cache->flexible('k', 30, 120, $factory));

        $this->clock->advance(10); // still fresh
        self::assertSame('value-1', $this->cache->flexible('k', 30, 120, $factory));
        self::assertSame(1, $calls);
    }

    public function testFlexibleServesStaleAndRefreshesInTheBackground(): void
    {
        $executor = new AfterResponseDeferredExecutor();
        $cache = new Cacheer(new ArrayStore($this->clock), $this->clock, $executor);

        $calls = 0;
        $factory = function () use (&$calls): string {
            $calls++;

            return 'value-' . $calls;
        };

        self::assertSame('value-1', $cache->flexible('k', 30, 120, $factory));

        // Past the fresh window but within the stale window: the caller still
        // gets the old value immediately, and the refresh is queued, not run.
        $this->clock->advance(40);
        self::assertSame('value-1', $cache->flexible('k', 30, 120, $factory));
        self::assertSame(1, $calls, 'Refresh must be deferred, not run inline.');
        self::assertSame(1, $executor->pending());

        // Running the deferred queue performs the refresh.
        $executor->flush();
        self::assertSame(2, $calls);
        self::assertSame('value-2', $cache->flexible('k', 30, 120, $factory));
    }

    public function testFlexibleRecomputesSynchronouslyOncePastTheStaleWindow(): void
    {
        $calls = 0;
        $factory = function () use (&$calls): string {
            $calls++;

            return 'value-' . $calls;
        };

        self::assertSame('value-1', $this->cache->flexible('k', 30, 120, $factory));

        $this->clock->advance(121); // past the hard stale window -> a real miss
        self::assertSame('value-2', $this->cache->flexible('k', 30, 120, $factory));
        self::assertSame(2, $calls);
    }

    public function testFlexibleRejectsAnInvalidWindow(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->cache->flexible('k', 120, 30, fn (): string => 'x');
    }
}
