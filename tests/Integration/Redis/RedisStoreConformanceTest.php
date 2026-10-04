<?php

declare(strict_types=1);

namespace Tests\Integration\Redis;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Predis\Client;
use Silviooosilva\CacheerPhp\Contracts\Store;
use Silviooosilva\CacheerPhp\Kernel\CacheEntry;
use Silviooosilva\CacheerPhp\Kernel\Key;
use Silviooosilva\CacheerPhp\Kernel\Scope;
use Silviooosilva\CacheerPhp\Kernel\Ttl;
use Silviooosilva\CacheerPhp\Storage\KeyEncoder\HashingKeyEncoder;
use Silviooosilva\CacheerPhp\Stores\RedisStore;
use Silviooosilva\CacheerPhp\Stores\Support\PredisConnection;
use Tests\Support\FakeClock;
use Tests\Support\StoreConformance;

final class RedisStoreConformanceTest extends StoreConformance
{
    private Client $client;

    private string $prefix;

    protected function createStore(FakeClock $clock): Store
    {
        $host = getenv('REDIS_HOST') ?: '127.0.0.1';
        $port = (int) (getenv('REDIS_PORT') ?: 6379);

        try {
            $this->client = new Client(['host' => $host, 'port' => $port]);
            $this->client->ping();
        } catch (\Throwable $exception) {
            self::markTestSkipped('Redis is not available: ' . $exception->getMessage());
        }

        $this->prefix = 'cacheer-test:' . bin2hex(random_bytes(4));

        return new RedisStore(new PredisConnection($this->client), $this->prefix, clock: $clock);
    }

    protected function expireLease(string $name, Ttl $ttl): void
    {
        // Redis expires the lease itself (PX); removing it is what expiry does.
        $this->client->del([$this->prefix . ':l:' . $name]);
    }

    protected function holdAtomicGuard(Key $key): callable
    {
        $guard = $this->prefix . ':lk:' . (new HashingKeyEncoder())->encode($key);
        $this->client->set($guard, 'another-worker');

        return function () use ($guard): void {
            $this->client->del([$guard]);
        };
    }

    protected function tearDown(): void
    {
        if (isset($this->client)) {
            $keys = $this->client->keys($this->prefix . ':*');
            if ($keys !== []) {
                $this->client->del($keys);
            }
        }

        parent::tearDown();
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
        return new RedisStore(new PredisConnection($this->client), $prefix, clock: $this->clock);
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
