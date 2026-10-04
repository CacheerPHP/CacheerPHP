<?php

declare(strict_types=1);

namespace Tests\Kernel;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Silviooosilva\CacheerPhp\Cacheer;
use Silviooosilva\CacheerPhp\Contracts\DeferredExecutor;
use Silviooosilva\CacheerPhp\Stores\ArrayStore;
use Silviooosilva\CacheerPhp\Support\AfterResponseDeferredExecutor;
use Tests\Support\FakeClock;
use Tests\Support\MinimalStore;

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

    public function testAStaleBurstSchedulesOneRefresh(): void
    {
        $executor = new AfterResponseDeferredExecutor();
        $cache = new Cacheer(new ArrayStore($this->clock), $this->clock, $executor);
        $calls = 0;
        $factory = static function () use (&$calls): string {
            return 'value-' . ++$calls;
        };

        $cache->flexible('k', 30, 120, $factory);
        $this->clock->advance(40);

        for ($i = 0; $i < 5; $i++) {
            self::assertSame('value-1', $cache->flexible('k', 30, 120, $factory));
        }

        self::assertSame(1, $executor->pending(), 'A burst of stale reads must queue one refresh.');
        $executor->flush();
        self::assertSame(2, $calls);
    }

    public function testACompletedRefreshSkipsQueuedDuplicates(): void
    {
        // A store that cannot lock cannot deduplicate scheduling, so every stale
        // read queues a task; the freshness recheck makes all but one a no-op.
        $executor = new AfterResponseDeferredExecutor();
        $cache = new Cacheer(new MinimalStore($this->clock), $this->clock, $executor);
        $calls = 0;
        $factory = static function () use (&$calls): string {
            return 'value-' . ++$calls;
        };

        $cache->flexible('k', 30, 120, $factory);
        $this->clock->advance(40);
        $cache->flexible('k', 30, 120, $factory);
        $cache->flexible('k', 30, 120, $factory);
        $cache->flexible('k', 30, 120, $factory);

        $executor->flush();
        self::assertSame(2, $calls, 'Only the first queued refresh may compute.');
    }

    public function testAValueBeyondTheHardStaleBoundaryRecomputesSynchronously(): void
    {
        $executor = new AfterResponseDeferredExecutor();
        $cache = new Cacheer(new ArrayStore($this->clock), $this->clock, $executor);

        // Written with a longer lifetime than this caller's stale window.
        $cache->set('k', 'ancient', 3600);
        $this->clock->advance(200);

        self::assertSame('recomputed', $cache->flexible('k', 30, 120, static fn (): string => 'recomputed'));
        self::assertSame(0, $executor->pending(), 'Past the stale window there is nothing to serve stale.');
        self::assertSame('recomputed', $cache->get('k'));
    }

    public function testAFailedRefreshReleasesItsPendingState(): void
    {
        $executor = new AfterResponseDeferredExecutor();
        $cache = new Cacheer(new ArrayStore($this->clock), $this->clock, $executor);

        $cache->flexible('k', 30, 120, static fn (): string => 'v1');
        $this->clock->advance(40);

        $cache->flexible('k', 30, 120, static function (): string {
            throw new RuntimeException('refresh failed');
        });
        $executor->flush(); // the failing refresh runs and is swallowed

        $cache->flexible('k', 30, 120, static fn (): string => 'v2');
        self::assertSame(1, $executor->pending(), 'A failed refresh must not block the next one.');
        $executor->flush();
        self::assertSame('v2', $cache->get('k'));
    }

    public function testAFailedScheduleReleasesItsPendingState(): void
    {
        $executor = new class () implements DeferredExecutor {
            public bool $failNext = true;

            /**
             * @var list<callable>
             */
            public array $tasks = [];

            public function defer(callable $task): void
            {
                if ($this->failNext) {
                    $this->failNext = false;

                    throw new RuntimeException('queue unavailable');
                }

                $this->tasks[] = $task;
            }
        };
        $cache = new Cacheer(new ArrayStore($this->clock), $this->clock, $executor);

        $cache->flexible('k', 30, 120, static fn (): string => 'v1');
        $this->clock->advance(40);

        try {
            $cache->flexible('k', 30, 120, static fn (): string => 'v2');
            self::fail('A failing executor must surface.');
        } catch (RuntimeException) {
        }

        $cache->flexible('k', 30, 120, static fn (): string => 'v2');
        self::assertCount(1, $executor->tasks, 'The failed schedule must not leave the refresh marked pending.');
    }
}
