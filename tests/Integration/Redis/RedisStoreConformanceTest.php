<?php

declare(strict_types=1);

namespace Tests\Integration\Redis;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Predis\Client;
use Silviooosilva\CacheerPhp\Contracts\RedisConnection;
use Silviooosilva\CacheerPhp\Contracts\Store;
use Silviooosilva\CacheerPhp\Kernel\CacheEntry;
use Silviooosilva\CacheerPhp\Kernel\Key;
use Silviooosilva\CacheerPhp\Kernel\Scope;
use Silviooosilva\CacheerPhp\Kernel\Ttl;
use Silviooosilva\CacheerPhp\Storage\KeyEncoder\HashingKeyEncoder;
use Silviooosilva\CacheerPhp\Stores\RedisStore;
use Silviooosilva\CacheerPhp\Stores\Support\PhpRedisConnection;
use Silviooosilva\CacheerPhp\Stores\Support\PredisConnection;
use Tests\Support\FakeClock;
use Tests\Support\StoreConformance;

final class RedisStoreConformanceTest extends StoreConformance
{
    private ?RedisConnection $connection = null;

    private string $prefix;

    protected function createStore(FakeClock $clock): Store
    {
        $this->prefix = 'cacheer-test:' . bin2hex(random_bytes(4));
        $this->connection = self::connect();

        return new RedisStore($this->connection, $this->prefix, clock: $clock);
    }

    /**
     * Connects with the client CI selects (REDIS_CLIENT=predis|phpredis), so
     * both advertised connection adapters run the same suite.
     */
    private static function connect(): RedisConnection
    {
        $client = getenv('REDIS_CLIENT') ?: 'predis';
        $host = getenv('REDIS_HOST') ?: '127.0.0.1';
        $port = (int) (getenv('REDIS_PORT') ?: 6379);
        $database = (int) (getenv('REDIS_DB') ?: 0);

        // A refused connection also raises a PHP warning before the client
        // throws; the exception carries the reason, so the warning is noise
        // that would turn a clean skip into a "warning" result.
        set_error_handler(static fn (): bool => true);

        try {
            if ($client === 'phpredis') {
                if (!extension_loaded('redis')) {
                    self::serviceUnavailable('CACHEER_REQUIRE_REDIS', 'REDIS_CLIENT=phpredis but the redis extension is not loaded.');
                }

                $redis = new \Redis();
                $redis->connect($host, $port, 2.0);
                $redis->select($database);
                $redis->ping();

                return new PhpRedisConnection($redis);
            }

            $predis = new Client(['host' => $host, 'port' => $port, 'database' => $database, 'timeout' => 2.0]);
            $predis->ping();

            return new PredisConnection($predis);
        } catch (\Throwable $exception) {
            self::serviceUnavailable('CACHEER_REQUIRE_REDIS', 'Redis is not available: ' . $exception->getMessage());
        } finally {
            restore_error_handler();
        }
    }

    protected function expireLease(string $name, Ttl $ttl): void
    {
        // Redis expires the lease itself (PX); removing it is what expiry does.
        $this->redis()->delete([$this->prefix . ':l:' . $name]);
    }

    protected function holdAtomicGuard(Key $key): callable
    {
        $guard = $this->prefix . ':lk:' . (new HashingKeyEncoder())->encode($key);
        $this->redis()->set($guard, 'another-worker', null);

        return function () use ($guard): void {
            $this->redis()->delete([$guard]);
        };
    }

    protected function tearDown(): void
    {
        // Only clean up when a connection was made; an unavailable server is a
        // skip (or, when required, a failure) — never a teardown error.
        if ($this->connection !== null) {
            $keys = [...$this->connection->scan($this->prefix . ':*')];
            if ($keys !== []) {
                $this->connection->delete($keys);
            }
        }

        parent::tearDown();
    }

    private function redis(): RedisConnection
    {
        assert($this->connection !== null);

        return $this->connection;
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function globLookalikePrefixes(): array
    {
        return [
            'star'      => ['app*', 'appX'],
            'question'  => ['app?', 'appX'],
            'class'     => ['app[XY]', 'appX'],
            'backslash' => ['app\\', 'app'],
        ];
    }

    #[DataProvider('globLookalikePrefixes')]
    public function testGlobCharactersInThePrefixMatchLiterally(string $target, string $neighbour): void
    {
        $store = $this->redisStore($this->prefix . ':' . $target);
        $other = $this->redisStore($this->prefix . ':' . $neighbour);
        $key = Key::named('k');
        $scoped = Key::named('k')->within(Scope::named('tenant'));

        $other->set($key, 'other', Ttl::forever());
        $other->set($scoped, 'other-scoped', Ttl::forever());
        $other->set(Key::named('short'), 'short', Ttl::seconds(1));
        $other->tag($key, 'group');

        $store->set($key, 'own', Ttl::forever());
        $store->set($scoped, 'own-scoped', Ttl::forever());
        $store->tag($key, 'group');

        self::assertSame(['own', 'own-scoped'], $this->entryValues($store->entries()));

        $store->clearScope(Scope::named('tenant'));
        self::assertSame('other-scoped', $other->get($scoped)->value());

        $this->clock->advance(2);
        self::assertSame(0, $store->prune(), 'prune() must not count or remove another store\'s entries.');

        $store->clear();
        self::assertTrue($store->get($key)->isMiss());
        self::assertSame('other', $other->get($key)->value());
        self::assertSame(1, $other->clearTag('group'), 'clear() must not remove another store\'s tags.');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function reservedSegments(): array
    {
        return [
            'entries'   => ['e'],
            'tags'      => ['t'],
            'locks'     => ['l'],
            'key locks' => ['lk'],
            'key tags'  => ['kt'],
        ];
    }

    #[DataProvider('reservedSegments')]
    public function testAPrefixCannotNestInsideAnotherStoresKeyspace(string $segment): void
    {
        $outer = $this->redisStore($this->prefix . ':' . 'app');
        $outer->set(Key::named('k'), 'outer', Ttl::forever());

        // "app:e", "app:t", ... would place this store's keys under the outer
        // store's own entry/tag/lock keyspace, where the outer store's clear()
        // and SCANs would reach them.
        $this->expectException(InvalidArgumentException::class);
        $this->redisStore($this->prefix . ':' . 'app:' . $segment);
    }

    public function testOrdinaryNamespacedPrefixesRemainAccepted(): void
    {
        $outer = $this->redisStore($this->prefix . ':' . 'app');
        $inner = $this->redisStore($this->prefix . ':' . 'app:cache');

        $outer->set(Key::named('k'), 'outer', Ttl::forever());
        $inner->set(Key::named('k'), 'inner', Ttl::forever());

        $outer->clear();

        self::assertSame('inner', $inner->get(Key::named('k'))->value());
    }

    private function redisStore(string $prefix): RedisStore
    {
        return new RedisStore($this->redis(), $prefix, clock: $this->clock);
    }

    /**
     * @param iterable<CacheEntry> $entries
     * @return list<mixed>
     */
    private function entryValues(iterable $entries): array
    {
        $values = [];
        foreach ($entries as $entry) {
            $values[] = $entry->value();
        }
        sort($values);

        return $values;
    }
}
