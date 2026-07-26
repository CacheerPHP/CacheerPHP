<?php

declare(strict_types=1);

namespace Tests\Kernel;

use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;
use RuntimeException;
use Silviooosilva\CacheerPhp\Contracts\Store;
use Silviooosilva\CacheerPhp\Kernel\CacheEntry;
use Silviooosilva\CacheerPhp\Kernel\Key;
use Silviooosilva\CacheerPhp\Kernel\Ttl;
use Silviooosilva\CacheerPhp\Observability\CacheEventType;
use Silviooosilva\CacheerPhp\Observability\EventBus;
use Silviooosilva\CacheerPhp\Observability\MetricsCollector;
use Silviooosilva\CacheerPhp\Observability\PsrLoggerSubscriber;
use Silviooosilva\CacheerPhp\Stores\ArrayStore;
use Silviooosilva\CacheerPhp\Stores\InstrumentedStore;
use Tests\Support\ArrayLogger;
use Tests\Support\FakeClock;

final class ObservabilityTest extends TestCase
{
    private FakeClock $clock;

    private EventBus $bus;

    private MetricsCollector $metrics;

    protected function setUp(): void
    {
        $this->clock = new FakeClock();
        $this->bus = new EventBus();
        $this->metrics = new MetricsCollector();
        $this->bus->listen($this->metrics->record(...));
    }

    private function store(bool $captureValues = false): InstrumentedStore
    {
        return new InstrumentedStore(new ArrayStore($this->clock), $this->bus, $captureValues);
    }

    public function testMetricsAggregateHitsMissesAndWrites(): void
    {
        $store = $this->store();

        $store->get(Key::named('a'));                 // miss
        $store->set(Key::named('a'), 'v', Ttl::forever());
        $store->get(Key::named('a'));                 // hit
        $store->delete(Key::named('a'));

        $snapshot = $this->metrics->snapshot();
        self::assertSame(1, $snapshot['hits']);
        self::assertSame(1, $snapshot['misses']);
        self::assertSame(1, $snapshot['writes']);
        self::assertSame(1, $snapshot['deletes']);
        self::assertSame(0.5, $snapshot['hit_rate']);
        self::assertGreaterThan(0, $snapshot['bytes_written']);
    }

    public function testValuesAreNotCapturedByDefault(): void
    {
        $captured = [];
        $this->bus->listen(function ($event) use (&$captured): void {
            $captured[] = $event;
        });

        $this->store()->set(Key::named('secret'), 'password123', Ttl::forever());

        $write = $captured[0];
        self::assertSame(CacheEventType::Write, $write->type);
        self::assertFalse($write->hasValue);
        self::assertNull($write->value);
        self::assertNotNull($write->bytes, 'Size is safe to record even without value capture.');
    }

    public function testOptInValueCaptureRunsThroughARedactor(): void
    {
        $store = new InstrumentedStore(
            new ArrayStore($this->clock),
            $this->bus,
            captureValues: true,
            redactor: static fn (mixed $v): string => '***redacted***',
        );

        $captured = null;
        $this->bus->listen(function ($event) use (&$captured): void {
            $captured = $event;
        });

        $store->set(Key::named('secret'), 'password123', Ttl::forever());

        self::assertTrue($captured->hasValue);
        self::assertSame('***redacted***', $captured->value);
    }

    public function testAFailingListenerCannotBreakACacheOperation(): void
    {
        $this->bus->listen(static function (): void {
            throw new RuntimeException('listener blew up');
        });

        $store = $this->store();
        $store->set(Key::named('k'), 'v', Ttl::forever());

        self::assertSame('v', $store->get(Key::named('k'))->value());
    }

    public function testStoreFailuresEmitAFailureEventAndRethrow(): void
    {
        $failing = new class () implements Store {
            public function get(Key $key): CacheEntry
            {
                throw new RuntimeException('backend down');
            }

            public function set(Key $key, mixed $value, Ttl $ttl): void
            {
            }

            public function delete(Key $key): bool
            {
                return false;
            }

            public function clear(): void
            {
            }
        };
        $store = new InstrumentedStore($failing, $this->bus);

        try {
            $store->get(Key::named('k'));
            self::fail('Expected the store failure to propagate.');
        } catch (RuntimeException) {
            self::assertSame(1, $this->metrics->count(CacheEventType::Failure));
        }
    }

    public function testPsrLoggerReceivesMetadataButNeverValues(): void
    {
        $logger = new ArrayLogger();
        $this->bus->listen((new PsrLoggerSubscriber($logger))->record(...));

        $store = $this->store(captureValues: true);
        $store->set(Key::named('token'), 'super-secret-value', Ttl::forever());

        $record = $logger->records[0];
        self::assertSame(LogLevel::DEBUG, $record['level']);
        self::assertSame('cache.write', $record['message']);
        self::assertArrayNotHasKey('value', $record['context']);
        self::assertStringNotContainsString('super-secret-value', json_encode($record['context']) ?: '');
    }
}
