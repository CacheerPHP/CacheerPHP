<?php

declare(strict_types=1);

namespace Silviooosilva\CacheerPhp;

use DateInterval;
use PDO;
use Silviooosilva\CacheerPhp\Config\CacheerBuilder;
use Silviooosilva\CacheerPhp\Config\CachePolicy;
use Silviooosilva\CacheerPhp\Config\PipelineConfig;
use Silviooosilva\CacheerPhp\Contracts\Cache;
use Silviooosilva\CacheerPhp\Contracts\Clock;
use Silviooosilva\CacheerPhp\Contracts\DeferredExecutor;
use Silviooosilva\CacheerPhp\Contracts\EventDispatcher;
use Silviooosilva\CacheerPhp\Contracts\Lock;
use Silviooosilva\CacheerPhp\Contracts\RedisConnection;
use Silviooosilva\CacheerPhp\Contracts\Store;
use Silviooosilva\CacheerPhp\Core\CacheOperations;
use Silviooosilva\CacheerPhp\Kernel\CacheEntry;
use Silviooosilva\CacheerPhp\Kernel\Key;
use Silviooosilva\CacheerPhp\Kernel\Scope;
use Silviooosilva\CacheerPhp\Kernel\Ttl;
use Silviooosilva\CacheerPhp\Observability\NullEventDispatcher;
use Silviooosilva\CacheerPhp\Observability\Telemetry;
use Silviooosilva\CacheerPhp\Stores\ArrayStore;
use Silviooosilva\CacheerPhp\Stores\DatabaseStore;
use Silviooosilva\CacheerPhp\Stores\FileStore;
use Silviooosilva\CacheerPhp\Stores\InstrumentedStore;
use Silviooosilva\CacheerPhp\Stores\RedisStore;
use Silviooosilva\CacheerPhp\Stores\ResilientStore;
use Silviooosilva\CacheerPhp\Stores\TieredStore;
use Silviooosilva\CacheerPhp\Support\CircuitBreaker;
use Silviooosilva\CacheerPhp\Support\FormattedCacheer;
use Silviooosilva\CacheerPhp\Support\SyncDeferredExecutor;
use Silviooosilva\CacheerPhp\Support\SystemClock;

/**
 * The explicit, instance-first v6 cache — the main class of CacheerPHP.
 *
 * The distinctive name (over a generic "Cache") keeps imports collision-free
 * next to a framework's own cache classes. Construct one with a named
 * constructor and inject it where you need it; there is no global state.
 *
 * Scope and policy are state on the object, not separate wrapper types, so every
 * combination composes and nothing is lost along the way:
 *
 *     $billing = $cache->in('billing')->withPolicy($policy);
 *     $billing->increment('invoices:issued');
 *     $billing->formatted()->get('summary')->toJson();
 *
 * Type-hint {@see Cache} in application code so any of those is substitutable.
 */
final readonly class Cacheer implements Cache
{
    /**
     * @var CacheOperations
     */
    private CacheOperations $operations;

    /**
     * @var Clock
     */
    private Clock $clock;

    /**
     * @var DeferredExecutor
     */
    private DeferredExecutor $executor;

    /**
     * @var EventDispatcher
     */
    private EventDispatcher $events;

    /**
     * @var Scope
     */
    private Scope $boundScope;

    /**
     * @var Store
     */
    private Store $store;

    /**
     * Build a cache over a store.
     *
     * When the global {@see Telemetry} tap has listeners — i.e. a telemetry
     * package such as cacheerphp/monitor is installed — the store is wrapped in
     * transparent instrumentation here, so *every* cache reports regardless of
     * how it was constructed. Pass your own $events (as
     * {@see self::instrumented()} does) or an already-instrumented store to opt
     * out; with no listeners registered this is a no-op and costs nothing.
     *
     * @param Store $store
     * @param ?Clock $clock
     * @param ?DeferredExecutor $executor
     * @param ?EventDispatcher $events
     * @param ?Scope $scope
     * @param ?CachePolicy $policy
     */
    public function __construct(
        Store $store,
        ?Clock $clock = null,
        ?DeferredExecutor $executor = null,
        ?EventDispatcher $events = null,
        ?Scope $scope = null,
        private ?CachePolicy $policy = null,
    ) {
        if ($events === null && self::tapApplies($store)) {
            $events = Telemetry::dispatcher();
            $store = new InstrumentedStore($store, $events, Telemetry::capturesValues());
        }

        $this->store = $store;
        $this->clock = $clock ?? new SystemClock();
        $this->executor = $executor ?? new SyncDeferredExecutor();
        $this->events = $events ?? new NullEventDispatcher();
        $this->boundScope = $scope ?? Scope::root();
        $this->operations = new CacheOperations(
            $this->store,
            $this->boundScope,
            $this->clock,
            $this->executor,
            $this->events,
            $this->policy,
        );
    }

    /**
     * Whether the global telemetry tap should wrap this store: only when a
     * listener is registered and the store is not already instrumented (so a
     * decorator chain is never double-wrapped and events are never duplicated).
     *
     * @param Store $store
     * @return bool
     */
    private static function tapApplies(Store $store): bool
    {
        return Telemetry::hasListeners() && !$store instanceof InstrumentedStore;
    }

    /**
     * Named constructor for the in-process array store: a dependency-free cache
     * that lives for the current request. Ideal for tests and short-lived CLI runs.
     *
     * @param ?Clock $clock
     * @return Cacheer
     */
    public static function inMemory(?Clock $clock = null): self
    {
        $clock ??= new SystemClock();

        return new self(new ArrayStore($clock), $clock);
    }

    /**
     * Named constructor for the filesystem store: persistent, dependency-free,
     * and safe to install without Redis or a database.
     *
     * @param string $directory
     * @param ?PipelineConfig $pipeline
     * @param ?Clock $clock
     * @return Cacheer
     */
    public static function file(string $directory, ?PipelineConfig $pipeline = null, ?Clock $clock = null): self
    {
        $clock ??= new SystemClock();

        return new self(new FileStore($directory, $pipeline?->codec(), clock: $clock), $clock);
    }

    /**
     * Named constructor for the database store. The PDO connection is injected;
     * create the schema explicitly with DatabaseStoreSchema::migrate() first.
     *
     * @param PDO $pdo
     * @param string $table
     * @param ?PipelineConfig $pipeline
     * @param ?Clock $clock
     * @return Cacheer
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
     *
     * @param RedisConnection $connection
     * @param string $prefix
     * @param ?PipelineConfig $pipeline
     * @param ?Clock $clock
     * @return Cacheer
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
     *
     * @param Store $l1
     * @param Store $l2
     * @param ?Ttl $l1MaxTtl
     * @param ?Clock $clock
     * @param ?DeferredExecutor $executor
     * @param ?EventDispatcher $events
     * @return Cacheer
     */
    public static function tiered(
        Store $l1,
        Store $l2,
        ?Ttl $l1MaxTtl = null,
        ?Clock $clock = null,
        ?DeferredExecutor $executor = null,
        ?EventDispatcher $events = null,
    ): self {
        $clock ??= new SystemClock();

        // TieredStore emits promotion events of its own, so it needs a dispatcher
        // up front rather than relying on the constructor's tap. With no explicit
        // one, borrow the telemetry tap while it is live so promotions are
        // reported too; $events stays null so the constructor still wraps the
        // tier for ordinary get/set instrumentation.
        $tierEvents = $events ?? (Telemetry::hasListeners()
            ? Telemetry::dispatcher()
            : new NullEventDispatcher());

        return new self(
            new TieredStore($l1, $l2, $clock, $l1MaxTtl, events: $tierEvents),
            $clock,
            $executor,
            $events,
        );
    }

    /**
     * Named constructor for a fault-tolerant cache: serve from a primary store,
     * fall back to another when a circuit breaker trips.
     *
     * @param Store $primary
     * @param Store $fallback
     * @param ?CircuitBreaker $breaker
     * @param ?Clock $clock
     * @param ?DeferredExecutor $executor
     * @return Cacheer
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

    /**
     * Named constructor that wraps a store in transparent instrumentation:
     * every operation is timed and emitted as a typed event through the given
     * dispatcher (which also carries the kernel's promotion/stale/refresh
     * events). Value capture is off by default.
     *
     * @param Store $store
     * @param EventDispatcher $events
     * @param bool $captureValues
     * @param (callable(mixed): mixed)|null $redactor
     * @param ?Clock $clock
     * @return Cacheer
     */
    public static function instrumented(
        Store $store,
        EventDispatcher $events,
        bool $captureValues = false,
        ?callable $redactor = null,
        ?Clock $clock = null,
    ): self {
        return new self(new InstrumentedStore($store, $events, $captureValues, $redactor), $clock, null, $events);
    }

    /**
     * Start a fluent builder that assembles a store, a storage pipeline, and an
     * optional default policy into a ready cache. See {@see CacheerBuilder}.
     *
     * @return CacheerBuilder
     */
    public static function build(): CacheerBuilder
    {
        return new CacheerBuilder();
    }

    /**
     * @param Key|string $key
     * @return CacheEntry
     */
    public function entry(string|Key $key): CacheEntry
    {
        return $this->operations->entry($key);
    }

    /**
     * @param Key|string $key
     * @param mixed $default
     * @return mixed
     */
    public function get(string|Key $key, mixed $default = null): mixed
    {
        return $this->operations->get($key, $default);
    }

    /**
     * @param Key|string $key
     * @return bool
     */
    public function has(string|Key $key): bool
    {
        return $this->operations->has($key);
    }

    /**
     * @param Key|string $key
     * @return bool
     */
    public function missing(string|Key $key): bool
    {
        return !$this->operations->has($key);
    }

    /**
     * @param iterable<string|Key> $keys
     * @param mixed $default
     * @return array<string, mixed>
     */
    public function many(iterable $keys, mixed $default = null): array
    {
        return $this->operations->many($keys, $default);
    }

    /**
     * @param Key|string $key
     * @param mixed $value
     * @param Ttl|DateInterval|string|int|null $ttl
     */
    public function set(
        string|Key $key,
        mixed $value,
        Ttl|DateInterval|int|string|null $ttl = null,
    ): void {
        $this->operations->set($key, $value, $ttl);
    }

    /**
     * @param Key|string $key
     * @param mixed $value
     */
    public function forever(string|Key $key, mixed $value): void
    {
        $this->operations->set($key, $value, Ttl::forever());
    }

    /**
     * @param Key|string $key
     * @param mixed $value
     * @param Ttl|DateInterval|string|int|null $ttl
     * @return bool
     */
    public function add(
        string|Key $key,
        mixed $value,
        Ttl|DateInterval|int|string|null $ttl = null,
    ): bool {
        return $this->operations->add($key, $value, $ttl);
    }

    /**
     * @param iterable<array-key, mixed> $values
     * @param Ttl|DateInterval|string|int|null $ttl
     */
    public function setMany(
        iterable $values,
        Ttl|DateInterval|int|string|null $ttl = null,
    ): void {
        $this->operations->setMany($values, $ttl);
    }

    /**
     * @param Key|string $key
     * @return bool
     */
    public function delete(string|Key $key): bool
    {
        return $this->operations->delete($key);
    }

    /**
     * @param Key|string $key
     * @param mixed $default
     * @return mixed
     */
    public function pull(string|Key $key, mixed $default = null): mixed
    {
        return $this->operations->pull($key, $default);
    }

    /**
     * @param iterable<string|Key> $keys
     * @return bool
     */
    public function deleteMany(iterable $keys): bool
    {
        return $this->operations->deleteMany($keys);
    }

    public function clear(): void
    {
        $this->operations->clear();
    }

    /**
     * @param Key|string $key
     * @param Ttl|DateInterval|string|int|null $ttl
     * @param callable $callback
     * @return mixed
     */
    public function remember(
        string|Key $key,
        Ttl|DateInterval|int|string|null $ttl,
        callable $callback,
    ): mixed {
        return $this->operations->remember($key, $ttl, $callback);
    }

    /**
     * @param Key|string $key
     * @param callable $callback
     * @return mixed
     */
    public function rememberForever(string|Key $key, callable $callback): mixed
    {
        return $this->operations->remember($key, Ttl::forever(), $callback);
    }

    /**
     * @param Key|string $key
     * @param int $fresh
     * @param int $stale
     * @param callable $callback
     * @return mixed
     */
    public function flexible(string|Key $key, int $fresh, int $stale, callable $callback): mixed
    {
        return $this->operations->flexible($key, $fresh, $stale, $callback);
    }

    /**
     * @param class-string $capability
     * @return bool
     */
    public function supports(string $capability): bool
    {
        return $this->operations->supports($capability);
    }

    /**
     * @param Key|string $key
     * @param int $amount
     * @param ?int $initial
     * @param Ttl|DateInterval|string|int|null $ttl
     * @return int
     */
    public function increment(
        string|Key $key,
        int $amount = 1,
        ?int $initial = null,
        Ttl|DateInterval|int|string|null $ttl = null,
    ): int {
        return $this->operations->increment($key, $amount, $initial, $ttl);
    }

    /**
     * @param Key|string $key
     * @param int $amount
     * @param ?int $initial
     * @param Ttl|DateInterval|string|int|null $ttl
     * @return int
     */
    public function decrement(
        string|Key $key,
        int $amount = 1,
        ?int $initial = null,
        Ttl|DateInterval|int|string|null $ttl = null,
    ): int {
        return $this->operations->increment($key, -$amount, $initial, $ttl);
    }

    /**
     * @param Key|string $key
     * @param Ttl|DateInterval|string|int $ttl
     * @return bool
     */
    public function touch(string|Key $key, Ttl|DateInterval|int|string $ttl): bool
    {
        return $this->operations->touch($key, $ttl);
    }

    /**
     * @param Key|string $key
     * @param string ...$tags
     */
    public function tag(string|Key $key, string ...$tags): void
    {
        $this->operations->tag($key, ...$tags);
    }

    /**
     * @param string $tag
     * @return int
     */
    public function flushTag(string $tag): int
    {
        return $this->operations->flushTag($tag);
    }

    /**
     * @param string $name
     * @param Ttl|DateInterval|string|int $ttl
     * @return Lock
     */
    public function lock(string $name, Ttl|DateInterval|int|string $ttl = 60): Lock
    {
        return $this->operations->lock($name, $ttl);
    }

    /**
     * @return iterable<CacheEntry>
     */
    public function entries(): iterable
    {
        return $this->operations->entries();
    }

    /**
     * @return int
     */
    public function prune(): int
    {
        return $this->operations->prune();
    }

    /**
     * @param Scope|string $scope
     * @return static
     */
    public function scope(string|Scope $scope): static
    {
        return $this->derive($this->operations->nestedScope($scope), $this->policy);
    }

    /**
     * @param Scope|string $scope
     * @return static
     */
    public function in(string|Scope $scope): static
    {
        return $this->scope($scope);
    }

    /**
     * @return Scope
     */
    public function boundScope(): Scope
    {
        return $this->boundScope;
    }

    /**
     * @param CachePolicy $policy
     * @return static
     */
    public function withPolicy(CachePolicy $policy): static
    {
        return $this->derive($this->boundScope, $policy);
    }

    /**
     * @return FormattedCacheer
     */
    public function formatted(): FormattedCacheer
    {
        return new FormattedCacheer($this);
    }

    /**
     * @return array
     */
    public function stats(): array
    {
        return $this->operations->stats();
    }

    /**
     * The store this cache drives. Application code should not need it — every
     * capability is reachable on this object, scope applied. It exists for store
     * authors, tests, and the CLI.
     *
     * @return Store
     */
    public function store(): Store
    {
        return $this->store;
    }

    /**
     * @param Scope $scope
     * @param ?CachePolicy $policy
     * @return Cacheer
     */
    private function derive(Scope $scope, ?CachePolicy $policy): self
    {
        return new self($this->store, $this->clock, $this->executor, $this->events, $scope, $policy);
    }
}
