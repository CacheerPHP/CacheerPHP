<?php

declare(strict_types=1);

namespace Tests\Contract;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Silviooosilva\CacheerPhp\Exceptions\CorruptedPayloadException;
use Silviooosilva\CacheerPhp\Exceptions\InvalidKeyException;
use Silviooosilva\CacheerPhp\Exceptions\StoreOperationFailedException;
use Silviooosilva\CacheerPhp\Kernel\Key;
use Silviooosilva\CacheerPhp\Kernel\Ttl;
use Silviooosilva\CacheerPhp\Stores\ArrayStore;
use Silviooosilva\CacheerPhp\Stores\ResilientStore;
use Silviooosilva\CacheerPhp\Support\CircuitBreaker;
use Tests\Support\FakeClock;
use Tests\Support\ToggleableStore;
use Throwable;
use TypeError;

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

    /**
     * @return array<string, array{\Closure(): Throwable}>
     */
    public static function nonAvailabilityErrors(): array
    {
        return [
            'corrupt payload' => [static fn (): Throwable => CorruptedPayloadException::truncatedHeader()],
            'invalid key'     => [static fn (): Throwable => new InvalidKeyException('bad key')],
            'programming bug' => [static fn (): Throwable => new TypeError('wrong type')],
        ];
    }

    /**
     * @param \Closure(): Throwable $error
     */
    #[DataProvider('nonAvailabilityErrors')]
    public function testOnlyAvailabilityErrorsFailOver(\Closure $error): void
    {
        $breaker = new CircuitBreaker($this->clock, failureThreshold: 1);
        $store = new ResilientStore($this->primary, $this->fallback, $breaker, $this->clock);
        $this->fallback->set(Key::named('k'), 'old fallback copy', Ttl::forever());
        $this->primary->failing = true;
        $this->primary->failWith = $error();

        try {
            $store->get(Key::named('k'));
            self::fail('The error must surface, not be masked by the fallback.');
        } catch (Throwable $thrown) {
            self::assertSame($this->primary->failWith, $thrown);
        }

        self::assertTrue($store->health()['healthy'], 'A bug or bad data is not an outage.');
    }

    public function testThePrimarysWriteResultIsAuthoritative(): void
    {
        $store = new ResilientStore($this->primary, $this->fallback, clock: $this->clock);
        $key = Key::named('only-on-primary');
        $this->primary->set($key, 'v', Ttl::forever());

        self::assertTrue($store->delete($key), 'The primary deleted it, even though the fallback never had it.');
    }

    public function testAFallbackMirrorFailureDoesNotFailAPrimaryWrite(): void
    {
        $fallback = new ToggleableStore(new ArrayStore($this->clock));
        $store = new ResilientStore($this->primary, $fallback, clock: $this->clock);
        $fallback->failing = true;

        $store->set(Key::named('k'), 'v', Ttl::forever());

        self::assertSame('v', $this->primary->get(Key::named('k'))->value());
    }

    public function testCountersRunOnThePrimaryOnlyAndFailClosedDuringAnOutage(): void
    {
        $breaker = new CircuitBreaker($this->clock, failureThreshold: 1);
        $store = new ResilientStore($this->primary, $this->fallback, $breaker, $this->clock);
        $key = Key::named('hits');
        $this->primary->set($key, 10, Ttl::forever());
        $this->fallback->set($key, 3, Ttl::forever()); // a diverged mirror

        self::assertSame(11, $store->increment($key));
        self::assertTrue($store->compareAndSwap($key, 11, 20));
        self::assertTrue($this->fallback->get($key)->isMiss(), 'The stale mirror is dropped, not served in an outage.');

        $this->primary->failing = true;
        try {
            $store->increment($key);
            self::fail('A counter must not silently move to the fallback.');
        } catch (StoreOperationFailedException $exception) {
            self::assertSame('increment', $exception->operation);
        }
        self::assertTrue($this->fallback->get($key)->isMiss());
    }

    public function testLocksStayOnThePrimaryAndAreNotAcquiredDuringAnOutage(): void
    {
        $breaker = new CircuitBreaker($this->clock, failureThreshold: 1);
        $store = new ResilientStore($this->primary, $this->fallback, $breaker, $this->clock);

        $held = $store->lock('job', Ttl::seconds(30));
        self::assertTrue($held->acquire());
        self::assertFalse($this->primary->lock('job', Ttl::seconds(30))->acquire(), 'The lock lives on the primary.');
        self::assertTrue($held->release());

        $this->primary->failing = true;
        $lock = $store->lock('job', Ttl::seconds(30));
        $before = $this->clock->nowFloat();

        self::assertFalse($lock->acquire());
        self::assertFalse($lock->block(5.0));
        self::assertSame($before, $this->clock->nowFloat(), 'An unavailable lock must not make callers wait.');
        self::assertTrue($this->fallback->lock('job', Ttl::seconds(30))->acquire(), 'No lock was taken on the fallback.');
    }

    public function testOutageWritesDoNotResurrectOldPrimaryDataOnRecovery(): void
    {
        $breaker = new CircuitBreaker($this->clock, failureThreshold: 1, recoverySeconds: 30.0);
        $store = new ResilientStore($this->primary, $this->fallback, $breaker, $this->clock);
        $deleted = Key::named('revoked');
        $changed = Key::named('profile');
        $store->set($deleted, 'granted', Ttl::forever());
        $store->set($changed, 'v1', Ttl::forever());

        $this->primary->failing = true;
        $store->get(Key::named('probe')); // trips the breaker
        $store->delete($deleted);
        $store->set($changed, 'v2', Ttl::forever());

        $this->primary->failing = false;
        $this->clock->advance(30);

        self::assertTrue($store->get($deleted)->isMiss(), 'A value deleted during the outage must not come back.');
        self::assertNotSame('v1', $store->get($changed)->valueOr(null), 'A value changed during the outage must not revert.');
        self::assertTrue($store->health()['healthy']);
    }

    public function testOutageBulkClearsAreReplayedOnRecovery(): void
    {
        $breaker = new CircuitBreaker($this->clock, failureThreshold: 1, recoverySeconds: 30.0);
        $store = new ResilientStore($this->primary, $this->fallback, $breaker, $this->clock);
        $store->set(Key::named('a'), 'old', Ttl::forever());

        $this->primary->failing = true;
        $store->get(Key::named('probe'));
        $store->clear();

        $this->primary->failing = false;
        $this->clock->advance(30);

        self::assertTrue($store->get(Key::named('a'))->isMiss());
    }

    public function testTooManyOutageWritesFallBackToClearingThePrimary(): void
    {
        $breaker = new CircuitBreaker($this->clock, failureThreshold: 1, recoverySeconds: 30.0);
        $store = new ResilientStore($this->primary, $this->fallback, $breaker, $this->clock);
        $store->set(Key::named('untouched'), 'old', Ttl::forever());

        $this->primary->failing = true;
        $store->get(Key::named('probe'));
        for ($i = 0; $i < 1_001; $i++) {
            $store->set(Key::named('outage-' . $i), $i, Ttl::forever());
        }

        $this->primary->failing = false;
        $this->clock->advance(30);

        self::assertTrue($store->get(Key::named('untouched'))->isMiss(), 'Past the tracking limit the primary is cleared.');
    }
}
