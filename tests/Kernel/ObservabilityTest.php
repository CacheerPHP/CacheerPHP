<?php

declare(strict_types=1);

namespace Tests\Kernel;

use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;
use RuntimeException;
use Silviooosilva\CacheerPhp\Cacheer;
use Silviooosilva\CacheerPhp\Contracts\BatchStore;
use Silviooosilva\CacheerPhp\Contracts\EventDispatcher;
use Silviooosilva\CacheerPhp\Contracts\Store;
use Silviooosilva\CacheerPhp\Kernel\CacheEntry;
use Silviooosilva\CacheerPhp\Kernel\Key;
use Silviooosilva\CacheerPhp\Kernel\Ttl;
use Silviooosilva\CacheerPhp\Observability\CacheEvent;
use Silviooosilva\CacheerPhp\Observability\CacheEventType;
use Silviooosilva\CacheerPhp\Observability\EventBus;
use Silviooosilva\CacheerPhp\Observability\MetricsCollector;
use Silviooosilva\CacheerPhp\Observability\PsrLoggerSubscriber;
use Silviooosilva\CacheerPhp\Stores\ArrayStore;
use Silviooosilva\CacheerPhp\Stores\DatabaseStore;
use Silviooosilva\CacheerPhp\Stores\InstrumentedStore;
use Silviooosilva\CacheerPhp\Stores\Support\DatabaseStoreSchema;
use Silviooosilva\CacheerPhp\Stores\TieredStore;
use Silviooosilva\CacheerPhp\Support\AfterResponseDeferredExecutor;
use Tests\Support\ArrayLogger;
use Tests\Support\FakeClock;
use Throwable;

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

    public function testAMonitoredBatchRollsBackLikeAnUnmonitoredOne(): void
    {
        foreach (['unmonitored' => false, 'monitored' => true] as $case => $monitored) {
            $pdo = new PDO('sqlite::memory:');
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            DatabaseStoreSchema::migrate($pdo, 'cacheer_store');
            $database = new DatabaseStore($pdo, clock: $this->clock);
            $store = $monitored ? new InstrumentedStore($database, $this->bus) : $database;

            try {
                // The second value cannot be serialized, so the batch fails midway.
                $store->setMany([
                    ['key' => Key::named('first'), 'value' => 'stored?'],
                    ['key' => Key::named('second'), 'value' => static fn (): null => null],
                ], Ttl::forever());
                self::fail('The batch must fail.');
            } catch (Throwable) {
            }

            self::assertTrue($database->get(Key::named('first'))->isMiss(), sprintf('%s: the failed batch must roll back.', $case));
        }
    }

    public function testNativeBatchCallsAreForwardedAndStillObserved(): void
    {
        $inner = new class (new ArrayStore($this->clock)) implements Store, BatchStore {
            /**
             * @var list<string>
             */
            public array $batchCalls = [];

            public function __construct(private readonly ArrayStore $delegate)
            {
            }

            public function get(Key $key): CacheEntry
            {
                return $this->delegate->get($key);
            }

            public function set(Key $key, mixed $value, Ttl $ttl): void
            {
                $this->delegate->set($key, $value, $ttl);
            }

            public function delete(Key $key): bool
            {
                return $this->delegate->delete($key);
            }

            public function clear(): void
            {
                $this->delegate->clear();
            }

            public function getMany(iterable $keys): array
            {
                $this->batchCalls[] = 'getMany';

                return $this->delegate->getMany($keys);
            }

            public function setMany(iterable $entries, Ttl $ttl): void
            {
                $this->batchCalls[] = 'setMany';
                $this->delegate->setMany($entries, $ttl);
            }

            public function deleteMany(iterable $keys): bool
            {
                $this->batchCalls[] = 'deleteMany';

                return $this->delegate->deleteMany($keys);
            }
        };
        $store = new InstrumentedStore($inner, $this->bus);

        $store->setMany([['key' => Key::named('a'), 'value' => 1], ['key' => Key::named('b'), 'value' => 2]], Ttl::forever());
        $store->getMany([Key::named('a'), Key::named('missing')]);
        $store->deleteMany([Key::named('a'), Key::named('b')]);

        self::assertSame(['setMany', 'getMany', 'deleteMany'], $inner->batchCalls);
        self::assertSame(2, $this->metrics->count(CacheEventType::Write));
        self::assertSame(1, $this->metrics->count(CacheEventType::Hit));
        self::assertSame(1, $this->metrics->count(CacheEventType::Miss));
        self::assertSame(2, $this->metrics->count(CacheEventType::Delete));
    }

    public function testAThrowingDispatcherCannotFailOrMaskAnOperation(): void
    {
        $throwing = new class () implements EventDispatcher {
            public function dispatch(CacheEvent $event): void
            {
                throw new RuntimeException('dispatcher blew up');
            }
        };

        // Not an EventBus, so nothing upstream isolates the failure.
        $store = new InstrumentedStore(new ArrayStore($this->clock), $throwing);
        $store->set(Key::named('k'), 'v', Ttl::forever());
        self::assertSame('v', $store->get(Key::named('k'))->value(), 'A completed write must stand.');

        $failing = new InstrumentedStore(new class () implements Store {
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
        }, $throwing);

        try {
            $failing->get(Key::named('k'));
            self::fail('The backend failure must propagate.');
        } catch (RuntimeException $exception) {
            self::assertSame('backend down', $exception->getMessage(), 'The listener must not mask the real error.');
        }

        // Kernel and decorator events are isolated too.
        $executor = new AfterResponseDeferredExecutor();
        $cache = new Cacheer(new TieredStore(new ArrayStore($this->clock), new ArrayStore($this->clock), $this->clock, events: $throwing), $this->clock, $executor, $throwing);
        self::assertSame('v1', $cache->flexible('f', 10, 100, static fn (): string => 'v1'));
        $this->clock->advance(20);
        self::assertSame('v1', $cache->flexible('f', 10, 100, static fn (): string => 'v2'), 'Stale-served events must not break flexible().');
        $executor->flush();
        self::assertSame('v2', $cache->get('f'));
    }

    public function testReadsDoNotCountAsBytesWritten(): void
    {
        $store = $this->store();
        $store->set(Key::named('a'), str_repeat('x', 100), Ttl::forever());
        $written = $this->metrics->snapshot()['bytes_written'];

        $store->get(Key::named('a'));
        $store->get(Key::named('a'));
        $store->getMany([Key::named('a')]);

        self::assertSame($written, $this->metrics->snapshot()['bytes_written']);
    }
}
