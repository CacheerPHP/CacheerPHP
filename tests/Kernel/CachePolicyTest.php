<?php

declare(strict_types=1);

namespace Tests\Kernel;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Silviooosilva\CacheerPhp\Cacheer;
use Silviooosilva\CacheerPhp\Config\CachePolicy;
use Silviooosilva\CacheerPhp\Exceptions\InvalidTtlException;
use Silviooosilva\CacheerPhp\Kernel\Ttl;
use Silviooosilva\CacheerPhp\Stores\ArrayStore;
use Tests\Support\FakeClock;

final class CachePolicyTest extends TestCase
{
    private FakeClock $clock;

    private Cacheer $cache;

    protected function setUp(): void
    {
        $this->clock = new FakeClock();
        $this->cache = new Cacheer(new ArrayStore($this->clock), $this->clock);
    }

    public function testDefaultTtlIsAppliedWhenNoneIsGiven(): void
    {
        $cache = $this->cache->withPolicy(CachePolicy::defaults()->withTtl(100));
        $cache->set('k', 'v');

        self::assertSame(100, $this->cache->entry('k')->remainingTtl($this->clock));
    }

    public function testJitterSpreadsTheTtlDeterministically(): void
    {
        // A fixed randomizer at 1.0 pushes a 100s TTL to the top of the +/-10% band.
        $policy = CachePolicy::defaults()->withTtl(100)->withJitter(0.10, static fn (): float => 1.0);
        $cache = $this->cache->withPolicy($policy);

        $cache->set('k', 'v');

        self::assertSame(110, $this->cache->entry('k')->remainingTtl($this->clock));
    }

    public function testNegativeCachingUsesAShorterTtlForEmptyValues(): void
    {
        $policy = CachePolicy::defaults()->withTtl(3600)->withNegativeTtl(30);
        $cache = $this->cache->withPolicy($policy);

        $cache->set('present', 'value');
        $cache->set('missing', null);

        self::assertSame(3600, $this->cache->entry('present')->remainingTtl($this->clock));
        self::assertSame(30, $this->cache->entry('missing')->remainingTtl($this->clock));
    }

    public function testServeStaleOnErrorReturnsTheLastGoodValueWhenTheCallbackFails(): void
    {
        $policy = CachePolicy::defaults()->withServeStaleOnError(60);
        $cache = $this->cache->withPolicy($policy);

        $value = $cache->remember('report', 100, fn (): string => 'fresh');
        self::assertSame('fresh', $value);

        // Move just past logical expiry, into the grace window.
        $this->clock->advance(101);

        $served = $cache->remember('report', 100, function (): string {
            throw new RuntimeException('upstream is down');
        });

        self::assertSame('fresh', $served, 'A failing refresh within grace must serve the stale value.');
    }

    public function testServeStaleOnErrorPropagatesWhenNoStaleValueExists(): void
    {
        $policy = CachePolicy::defaults()->withServeStaleOnError(60);
        $cache = $this->cache->withPolicy($policy);

        $this->expectException(RuntimeException::class);
        $cache->remember('cold', 100, function (): string {
            throw new RuntimeException('nothing cached to fall back to');
        });
    }

    public function testNegativeCachingDoesNotShortenAnExplicitForever(): void
    {
        $cache = $this->cache->withPolicy(CachePolicy::defaults()->withTtl(3600)->withNegativeTtl(30));

        $cache->forever('forever', null);
        $cache->set('forever-string', '', 'forever');
        $cache->rememberForever('remembered', static fn (): array => []);

        foreach (['forever', 'forever-string', 'remembered'] as $key) {
            self::assertNull($this->cache->entry($key)->remainingTtl($this->clock), sprintf('"%s" must stay forever.', $key));
        }

        // Without an explicit TTL, an empty value still gets the negative TTL.
        $cache->set('implicit', null);
        self::assertSame(30, $this->cache->entry('implicit')->remainingTtl($this->clock));
    }

    public function testNegativeCachingNeverLengthensAnExplicitTtl(): void
    {
        $cache = $this->cache->withPolicy(CachePolicy::defaults()->withNegativeTtl(30));

        $cache->set('short', null, 5);
        $cache->set('long', null, 600);

        self::assertSame(5, $this->cache->entry('short')->remainingTtl($this->clock));
        self::assertSame(30, $this->cache->entry('long')->remainingTtl($this->clock));
    }

    public function testServeStaleGraceThatOverflowsTheTtlFailsWithoutWriting(): void
    {
        $cache = $this->cache->withPolicy(CachePolicy::defaults()->withServeStaleOnError(60));

        try {
            $cache->remember('huge', Ttl::seconds(PHP_INT_MAX), static fn (): string => 'value');
            self::fail('A TTL plus grace beyond the platform limit must be rejected.');
        } catch (InvalidTtlException) {
            $this->addToAssertionCount(1);
        }

        self::assertFalse($this->cache->has('huge'));
    }
}
