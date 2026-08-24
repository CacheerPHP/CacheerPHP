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
    private CacheOperations $operations;

    private Clock $clock;

    private DeferredExecutor $executor;

    private EventDispatcher $events;

    private Scope $boundScope;

    public function __construct(
        private Store $store,
        ?Clock $clock = null,
        ?DeferredExecutor $executor = null,
        ?EventDispatcher $events = null,
        ?Scope $scope = null,
        private ?CachePolicy $policy = null,
    ) {
        $this->clock = $clock ?? new SystemClock();
        $this->executor = $executor ?? new SyncDeferredExecutor();
        $this->events = $events ?? new NullEventDispatcher();
        $this->boundScope = $scope ?? Scope::root();
        $this->operations = new CacheOperations(
            $store,
            $this->boundScope,
            $this->clock,
            $this->executor,
            $this->events,
            $policy,
        );
    }

    // ------------------------------------------------------ named constructors --

    /**
     * Named constructor for the in-process array store: a dependency-free cache
     * that lives for the current request. Ideal for tests and short-lived CLI runs.
     */
    public static function inMemory(?Clock $clock = null): self
    {
        $clock ??= new SystemClock();

        return self::boot(new ArrayStore($clock), $clock);
    }

    /**
     * Named constructor for the filesystem store: persistent, dependency-free,
     * and safe to install without Redis or a database.
     */
    public static function file(string $directory, ?PipelineConfig $pipeline = null, ?Clock $clock = null): self
    {
        $clock ??= new SystemClock();

        return self::boot(new FileStore($directory, $pipeline?->codec(), clock: $clock), $clock);
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

        return self::boot(new DatabaseStore($pdo, $table, $pipeline?->codec(), clock: $clock), $clock);
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

        return self::boot(new RedisStore($connection, $prefix, $pipeline?->codec(), clock: $clock), $clock);
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
        ?EventDispatcher $events = null,
    ): self {
        $clock ??= new SystemClock();
        $events ??= new NullEventDispatcher();

        return new self(new TieredStore($l1, $l2, $clock, $l1MaxTtl, events: $events), $clock, $executor, $events);
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

    /**
     * Named constructor that wraps a store in transparent instrumentation:
     * every operation is timed and emitted as a typed event through the given
     * dispatcher (which also carries the kernel's promotion/stale/refresh
     * events). Value capture is off by default.
     *
     * @param (callable(mixed): mixed)|null $redactor
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
     */
    public static function build(): CacheerBuilder
    {
        return new CacheerBuilder();
    }

    // ------------------------------------------------------------------ read --

    public function entry(string|Key $key): CacheEntry
    {
        return $this->operations->entry($key);
    }

    public function get(string|Key $key, mixed $default = null): mixed
    {
        return $this->operations->get($key, $default);
    }

    public function has(string|Key $key): bool
    {
        return $this->operations->has($key);
    }

    public function missing(string|Key $key): bool
    {
        return !$this->operations->has($key);
    }

    /**
     * @param iterable<string|Key> $keys
     * @return array<string, mixed>
     */
    public function many(iterable $keys, mixed $default = null): array
    {
        return $this->operations->many($keys, $default);
    }

    // ----------------------------------------------------------------- write --

    public function set(
        string|Key $key,
        mixed $value,
        Ttl|DateInterval|int|string|null $ttl = null,
    ): void {
        $this->operations->set($key, $value, $ttl);
    }

    public function forever(string|Key $key, mixed $value): void
    {
        $this->operations->set($key, $value, Ttl::forever());
    }

    public function add(
        string|Key $key,
        mixed $value,
        Ttl|DateInterval|int|string|null $ttl = null,
    ): bool {
        return $this->operations->add($key, $value, $ttl);
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

    public function delete(string|Key $key): bool
    {
        return $this->operations->delete($key);
    }

    public function pull(string|Key $key, mixed $default = null): mixed
    {
        return $this->operations->pull($key, $default);
    }

    /**
     * @param iterable<string|Key> $keys
     */
    public function deleteMany(iterable $keys): bool
    {
        return $this->operations->deleteMany($keys);
    }

    public function clear(): void
    {
        $this->operations->clear();
    }

    // --------------------------------------------------------------- compute --

    public function remember(
        string|Key $key,
        Ttl|DateInterval|int|string|null $ttl,
        callable $callback,
    ): mixed {
        return $this->operations->remember($key, $ttl, $callback);
    }

    public function rememberForever(string|Key $key, callable $callback): mixed
    {
        return $this->operations->remember($key, Ttl::forever(), $callback);
    }

    public function flexible(string|Key $key, int $fresh, int $stale, callable $callback): mixed
    {
        return $this->operations->flexible($key, $fresh, $stale, $callback);
    }

    // ---------------------------------------------------------- capabilities --

    /**
     * @param class-string $capability
     */
    public function supports(string $capability): bool
    {
        return $this->operations->supports($capability);
    }

    public function increment(
        string|Key $key,
        int $amount = 1,
        ?int $initial = null,
        Ttl|DateInterval|int|string|null $ttl = null,
    ): int {
        return $this->operations->increment($key, $amount, $initial, $ttl);
    }

    public function decrement(
        string|Key $key,
        int $amount = 1,
        ?int $initial = null,
        Ttl|DateInterval|int|string|null $ttl = null,
    ): int {
        return $this->operations->increment($key, -$amount, $initial, $ttl);
    }

    public function touch(string|Key $key, Ttl|DateInterval|int|string $ttl): bool
    {
        return $this->operations->touch($key, $ttl);
    }

    public function tag(string|Key $key, string ...$tags): void
    {
        $this->operations->tag($key, ...$tags);
    }

    public function flushTag(string $tag): int
    {
        return $this->operations->flushTag($tag);
    }

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

    public function prune(): int
    {
        return $this->operations->prune();
    }

    // ----------------------------------------------------------------- views --

    public function scope(string|Scope $scope): static
    {
        return $this->derive($this->operations->nestedScope($scope), $this->policy);
    }

    public function in(string|Scope $scope): static
    {
        return $this->scope($scope);
    }

    public function boundScope(): Scope
    {
        return $this->boundScope;
    }

    public function withPolicy(CachePolicy $policy): static
    {
        return $this->derive($this->boundScope, $policy);
    }

    public function formatted(): FormattedCacheer
    {
        return new FormattedCacheer($this);
    }

    /**
     * @return array{store: string, scope: string, policy: bool, capabilities: array<string, bool>}
     */
    public function stats(): array
    {
        return $this->operations->stats();
    }

    /**
     * The store this cache drives. Application code should not need it — every
     * capability is reachable on this object, scope applied. It exists for store
     * authors, tests, and the CLI.
     */
    public function store(): Store
    {
        return $this->store;
    }

    /**
     * Build a cache over the given store, transparently attaching the global
     * telemetry tap when {@see Telemetry} has listeners (e.g. cacheerphp/monitor
     * is installed). With no listeners this is exactly `new self($store, $clock)`
     * — no instrumentation, no overhead, no behavior change.
     */
    private static function boot(Store $store, Clock $clock, ?DeferredExecutor $executor = null): self
    {
        if (!Telemetry::hasListeners()) {
            return new self($store, $clock, $executor);
        }

        $events = Telemetry::dispatcher();

        return new self(
            new InstrumentedStore($store, $events, Telemetry::capturesValues()),
            $clock,
            $executor,
            $events,
        );
    }

    private function derive(Scope $scope, ?CachePolicy $policy): self
    {
        return new self($this->store, $this->clock, $this->executor, $this->events, $scope, $policy);
    }
}
