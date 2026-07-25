<?php

declare(strict_types=1);

namespace Tests\Contract;

use PHPUnit\Framework\TestCase;
use Silviooosilva\CacheerPhp\Kernel\Key;
use Silviooosilva\CacheerPhp\Kernel\Ttl;
use Silviooosilva\CacheerPhp\Stores\ArrayStore;
use Silviooosilva\CacheerPhp\Stores\ResilientStore;
use Silviooosilva\CacheerPhp\Support\CircuitBreaker;
use Tests\Support\FakeClock;
use Tests\Support\ToggleableStore;

final class ResilientStoreBehaviorTest extends TestCase
{
    private FakeClock $clock;

    private ToggleableStore $primary;

    private ArrayStore $fallback;

    protected function setUp(): void
    {
        $this->clock = new FakeClock();
        $this->primary = new ToggleableStore(new ArrayStore($this->clock));
        $this->fallback = new ArrayStore($this->clock);
    }

    public function testWritesKeepTheFallbackWarmAndReadsFailOverToIt(): void
    {
        $store = new ResilientStore($this->primary, $this->fallback, clock: $this->clock);
        $key = Key::named('user:1');

        $store->set($key, 'Ada', Ttl::forever());
        self::assertSame('Ada', $this->fallback->get($key)->value(), 'Writes must also reach the fallback.');

        $this->primary->failing = true;
        self::assertSame('Ada', $store->get($key)->value(), 'A failing primary must fail over to the fallback.');
    }

    public function testBreakerOpensAfterThresholdAndShortCircuitsThePrimary(): void
    {
        $breaker = new CircuitBreaker($this->clock, failureThreshold: 3, recoverySeconds: 30.0);
        $store = new ResilientStore($this->primary, $this->fallback, $breaker, $this->clock);
        $this->primary->failing = true;

        for ($i = 0; $i < 3; $i++) {
            $store->get(Key::named('k'));
        }

        self::assertSame(CircuitBreaker::OPEN, $store->health()['state']);
        self::assertFalse($store->health()['healthy']);

        $attemptsBefore = $this->primary->attempts;
        $store->get(Key::named('k'));
        self::assertSame($attemptsBefore, $this->primary->attempts, 'An open breaker must not touch the primary.');
    }

    public function testBreakerRecoversThroughHalfOpenAfterTheWindow(): void
    {
        $breaker = new CircuitBreaker($this->clock, failureThreshold: 2, recoverySeconds: 30.0);
        $store = new ResilientStore($this->primary, $this->fallback, $breaker, $this->clock);

        $this->primary->failing = true;
        $store->get(Key::named('k'));
        $store->get(Key::named('k'));
        self::assertSame(CircuitBreaker::OPEN, $store->health()['state']);

        // Primary comes back; after the recovery window a probe closes the breaker.
        $this->primary->failing = false;
        $this->clock->advance(30);

        $store->get(Key::named('k'));
        self::assertSame(CircuitBreaker::CLOSED, $store->health()['state']);
        self::assertTrue($store->health()['healthy']);
    }

    public function testHealthNeverLeaksMoreThanBreakerState(): void
    {
        $store = new ResilientStore($this->primary, $this->fallback, clock: $this->clock);

        self::assertSame(['state', 'healthy'], array_keys($store->health()));
    }
}
