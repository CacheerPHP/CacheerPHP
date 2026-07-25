<?php

declare(strict_types=1);

namespace Silviooosilva\CacheerPhp\Kernel;

use DateInterval;
use PDO;
use Silviooosilva\CacheerPhp\Config\CachePolicy;
use Silviooosilva\CacheerPhp\Config\PipelineConfig;
use Silviooosilva\CacheerPhp\Contracts\Clock;
use Silviooosilva\CacheerPhp\Contracts\DeferredExecutor;
use Silviooosilva\CacheerPhp\Contracts\RedisConnection;
use Silviooosilva\CacheerPhp\Contracts\Store;
use Silviooosilva\CacheerPhp\Core\CacheOperations;
use Silviooosilva\CacheerPhp\Stores\ArrayStore;
use Silviooosilva\CacheerPhp\Stores\DatabaseStore;
use Silviooosilva\CacheerPhp\Stores\FileStore;
use Silviooosilva\CacheerPhp\Stores\RedisStore;
use Silviooosilva\CacheerPhp\Stores\ResilientStore;
use Silviooosilva\CacheerPhp\Stores\TieredStore;
use Silviooosilva\CacheerPhp\Support\CircuitBreaker;
use Silviooosilva\CacheerPhp\Support\SyncDeferredExecutor;
use Silviooosilva\CacheerPhp\Support\SystemClock;

/**
 * Explicit, instance-first v6 cache API.
 */
final readonly class Cache
{
    private CacheOperations $operations;

    private Clock $clock;

    private DeferredExecutor $executor;

    public function __construct(
        private Store $store,
        ?Clock $clock = null,
        ?DeferredExecutor $executor = null,
    ) {
        $this->clock = $clock ?? new SystemClock();
        $this->executor = $executor ?? new SyncDeferredExecutor();
        $this->operations = new CacheOperations($store, Scope::root(), $this->clock, $this->executor);
    }

    /**
     * Named constructor for the in-process array store: a dependency-free cache
     * that lives for the current request. Ideal for tests and short-lived CLI runs.
     */
    public static function inMemory(?Clock $clock = null): self
    {
        $clock ??= new SystemClock();

        return new self(new ArrayStore($clock), $clock);
    }

    /**
     * Named constructor for the filesystem store: persistent, dependency-free,
     * and safe to install without Redis or a database.
     */
    public static function file(string $directory, ?PipelineConfig $pipeline = null, ?Clock $clock = null): self
    {
        $clock ??= new SystemClock();

        return new self(new FileStore($directory, $pipeline?->codec(), clock: $clock), $clock);
    }

    /**
     * Named constructor for the database store. The PDO connection is injected;
     * create the schema explicitly with DatabaseStoreSchema::migrate() first.
     */
    public static function database(
        PDO $pdo,
        string $table = 'cacheer_store',
        ?PipelineConfig $pipeline = null,
        ?Clock $clock = null,
    ): self {
        $clock ??= new SystemClock();

        return new self(new DatabaseStore($pdo, $table, $pipeline?->codec(), clock: $clock), $clock);
    }

    /**
     * Named constructor for the Redis store, driven by an injected connection
     * adapter (PredisConnection, PhpRedisConnection, or a custom one).
     */
    public static function redis(
        RedisConnection $connection,
        string $prefix = 'cacheer',
        ?PipelineConfig $pipeline = null,
        ?Clock $clock = null,
    ): self {
        $clock ??= new SystemClock();

        return new self(new RedisStore($connection, $prefix, $pipeline?->codec(), clock: $clock), $clock);
    }

    /**
     * Named constructor for a tiered L1/L2 cache: a fast local store in front of
     * a shared one, with promotion and generation-based coherence.
     */
    public static function tiered(
        Store $l1,
        Store $l2,
        ?Ttl $l1MaxTtl = null,
        ?Clock $clock = null,
        ?DeferredExecutor $executor = null,
    ): self {
        $clock ??= new SystemClock();

        return new self(new TieredStore($l1, $l2, $clock, $l1MaxTtl), $clock, $executor);
    }

    /**
     * Named constructor for a fault-tolerant cache: serve from a primary store,
     * fall back to another when a circuit breaker trips.
     */
    public static function resilient(
        Store $primary,
        Store $fallback,
        ?CircuitBreaker $breaker = null,
        ?Clock $clock = null,
        ?DeferredExecutor $executor = null,
    ): self {
        $clock ??= new SystemClock();

        return new self(new ResilientStore($primary, $fallback, $breaker, $clock), $clock, $executor);
    }

    public function entry(string|Key $key): CacheEntry
    {
        return $this->operations->entry($key);
    }

    public function get(string|Key $key, mixed $default = null): mixed
    {
        return $this->operations->get($key, $default);
    }

    public function set(
        string|Key $key,
        mixed $value,
        Ttl|DateInterval|int|string|null $ttl = null,
    ): void {
        $this->operations->set($key, $value, $ttl);
    }

    public function delete(string|Key $key): bool
    {
        return $this->operations->delete($key);
    }

    public function clear(): void
    {
        $this->operations->clear();
    }

    public function has(string|Key $key): bool
    {
        return $this->operations->has($key);
    }

    public function remember(
        string|Key $key,
        Ttl|DateInterval|int|string|null $ttl,
        callable $callback,
    ): mixed {
        return $this->operations->remember($key, $ttl, $callback);
    }

    /**
     * Stale-while-revalidate: serve fresh for $fresh seconds, then serve the
     * stale value while a single worker refreshes it (deferred via the executor)
     * until $stale seconds, after which it is recomputed synchronously.
     */
    public function flexible(string|Key $key, int $fresh, int $stale, callable $callback): mixed
    {
        return $this->operations->flexible($key, $fresh, $stale, $callback);
    }

    /**
     * @param iterable<string|Key> $keys
     * @return array<string, mixed>
     */
    public function many(iterable $keys, mixed $default = null): array
    {
        return $this->operations->many($keys, $default);
    }

    /**
     * @param iterable<array-key, mixed> $values
     */
    public function setMany(
        iterable $values,
        Ttl|DateInterval|int|string|null $ttl = null,
    ): void {
        $this->operations->setMany($values, $ttl);
    }

    /**
     * @param iterable<string|Key> $keys
     */
    public function deleteMany(iterable $keys): bool
    {
        return $this->operations->deleteMany($keys);
    }

    /**
     * Wrap this cache with a policy (default TTL, jitter, negative caching,
     * serve-stale-on-error). Reads pass through; writes and remember() honor it.
     */
    public function withPolicy(CachePolicy $policy): PolicyCache
    {
        return new PolicyCache($this, $policy, $this->clock);
    }

    public function scope(string|Scope $scope): ScopedCache
    {
        return new ScopedCache(
            $this->store,
            $this->operations->nestedScope($scope),
            $this->clock,
            $this->executor,
        );
    }
}
