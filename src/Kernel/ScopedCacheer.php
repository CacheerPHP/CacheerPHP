<?php

declare(strict_types=1);

namespace Silviooosilva\CacheerPhp\Kernel;

use DateInterval;
use Silviooosilva\CacheerPhp\Contracts\Clock;
use Silviooosilva\CacheerPhp\Contracts\DeferredExecutor;
use Silviooosilva\CacheerPhp\Contracts\EventDispatcher;
use Silviooosilva\CacheerPhp\Contracts\Store;
use Silviooosilva\CacheerPhp\Core\CacheOperations;
use Silviooosilva\CacheerPhp\Observability\NullEventDispatcher;
use Silviooosilva\CacheerPhp\Support\FormattedCacheer;
use Silviooosilva\CacheerPhp\Support\SyncDeferredExecutor;
use Silviooosilva\CacheerPhp\Support\SystemClock;

/**
 * Immutable cache view that prefixes every operation with a typed scope.
 *
 * Returned by {@see \Silviooosilva\CacheerPhp\Cacheer::scope()}; exposes the same
 * read/write surface bound to a scope.
 */
final readonly class ScopedCacheer
{
    private CacheOperations $operations;

    private Clock $clock;

    private DeferredExecutor $executor;

    private EventDispatcher $events;

    public function __construct(
        private Store $store,
        private Scope $scope,
        ?Clock $clock = null,
        ?DeferredExecutor $executor = null,
        ?EventDispatcher $events = null,
    ) {
        $this->clock = $clock ?? new SystemClock();
        $this->executor = $executor ?? new SyncDeferredExecutor();
        $this->events = $events ?? new NullEventDispatcher();
        $this->operations = new CacheOperations($store, $scope, $this->clock, $this->executor, $this->events);
    }

    public function name(): Scope
    {
        return $this->scope;
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
     * A read-formatting view over this scope: reads return a CacheDataFormatter.
     * See {@see FormattedCacheer}.
     */
    public function formatted(): FormattedCacheer
    {
        return new FormattedCacheer($this);
    }

    public function scope(string|Scope $scope): self
    {
        return new self(
            $this->store,
            $this->operations->nestedScope($scope),
            $this->clock,
            $this->executor,
            $this->events,
        );
    }
}
