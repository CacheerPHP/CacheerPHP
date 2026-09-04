<?php

declare(strict_types=1);

namespace Silviooosilva\CacheerPhp\Contracts;

use DateInterval;
use Silviooosilva\CacheerPhp\Config\CachePolicy;
use Silviooosilva\CacheerPhp\Kernel\CacheEntry;
use Silviooosilva\CacheerPhp\Kernel\Key;
use Silviooosilva\CacheerPhp\Kernel\Scope;
use Silviooosilva\CacheerPhp\Kernel\Ttl;
use Silviooosilva\CacheerPhp\Support\FormattedCacheer;

/**
 * The cache surface application code depends on.
 *
 * Type-hint this, not the concrete {@see \Silviooosilva\CacheerPhp\Cacheer}, so a
 * scoped or policy-bound cache is substitutable for any other. Scope, policy,
 * and capability access are all properties of one object rather than separate
 * wrapper types, so every combination composes: a scoped cache still has a
 * policy, a policy-bound cache still scopes, and both still increment and lock.
 *
 * Implementing a *backend* means implementing {@see Store} (four methods) plus
 * whichever capability interfaces you can honor — not this.
 */
interface Cache
{
    /**
     * The full entry — hit/miss, value, timestamps — rather than just the value.
     * The only way to distinguish a stored null from a miss.
     *
     * @param Key|string $key
     * @return CacheEntry
     */
    public function entry(string|Key $key): CacheEntry;

    /**
     * @param Key|string $key
     * @param mixed $default
     * @return mixed
     */
    public function get(string|Key $key, mixed $default = null): mixed;

    /**
     * @param Key|string $key
     * @return bool
     */
    public function has(string|Key $key): bool;

    /**
     * Inverse of {@see self::has()}; reads better in a guard clause.
     *
     * @param Key|string $key
     * @return bool
     */
    public function missing(string|Key $key): bool;

    /**
     * @param iterable<string|Key> $keys
     * @param mixed $default
     * @return array<string, mixed>
     */
    public function many(iterable $keys, mixed $default = null): array;

    /**
     * @param Key|string $key
     * @param mixed $value
     * @param Ttl|DateInterval|string|int|null $ttl
     */
    public function set(string|Key $key, mixed $value, Ttl|DateInterval|int|string|null $ttl = null): void;

    /**
     * Store with no expiry. Explicit about intent where `set($k, $v)` only
     * implies it.
     *
     * @param Key|string $key
     * @param mixed $value
     */
    public function forever(string|Key $key, mixed $value): void;

    /**
     * Store only if the key is absent; true when this call was the one that
     * stored it. Serialized by a lock when the store can lock, so it is a sound
     * first-writer-wins across processes; otherwise it is single-process only.
     *
     * @param Key|string $key
     * @param mixed $value
     * @param Ttl|DateInterval|string|int|null $ttl
     * @return bool
     */
    public function add(string|Key $key, mixed $value, Ttl|DateInterval|int|string|null $ttl = null): bool;

    /**
     * @param iterable<array-key, mixed> $values
     * @param Ttl|DateInterval|string|int|null $ttl
     */
    public function setMany(iterable $values, Ttl|DateInterval|int|string|null $ttl = null): void;

    /**
     * @param Key|string $key
     * @return bool
     */
    public function delete(string|Key $key): bool;

    /**
     * Read and delete in one call, returning the value the key held.
     *
     * @param Key|string $key
     * @param mixed $default
     * @return mixed
     */
    public function pull(string|Key $key, mixed $default = null): mixed;

    /**
     * @param iterable<string|Key> $keys
     * @return bool
     */
    public function deleteMany(iterable $keys): bool;

    /**
     * Empty this cache — the whole store at root, or just this scope's keyspace
     * when scoped.
     */
    public function clear(): void;

    /**
     * Return the cached value, or compute, store, and return it. Single-flighted
     * across workers when the store can lock, so a miss on a hot key does not
     * stampede the origin.
     *
     * @param Key|string $key
     * @param Ttl|DateInterval|string|int|null $ttl
     * @param callable $callback
     * @return mixed
     */
    public function remember(string|Key $key, Ttl|DateInterval|int|string|null $ttl, callable $callback): mixed;

    /**
     * @param Key|string $key
     * @param callable $callback
     * @return mixed
     */
    public function rememberForever(string|Key $key, callable $callback): mixed;

    /**
     * Stale-while-revalidate: serve fresh for $fresh seconds, then serve the
     * stale value while one worker refreshes it, until $stale seconds, after
     * which it is recomputed synchronously.
     *
     * @param Key|string $key
     * @param int $fresh
     * @param int $stale
     * @param callable $callback
     * @return mixed
     */
    public function flexible(string|Key $key, int $fresh, int $stale, callable $callback): mixed;

    /**
     * Whether the underlying store really honors a capability interface — ask
     * before calling a method below if you support pluggable backends.
     *
     * @param class-string $capability
     * @return bool
     */
    public function supports(string $capability): bool;

    /**
     * Atomically add to a counter and return its new value. With $initial set, a
     * missing key is created as ($initial + $amount).
     *
     * @param Key|string $key
     * @param int $amount
     * @param ?int $initial
     * @param Ttl|DateInterval|string|int|null $ttl
     * @return int
     * @throws \Silviooosilva\CacheerPhp\Exceptions\UnsupportedCapabilityException
     */
    public function increment(
        string|Key $key,
        int $amount = 1,
        ?int $initial = null,
        Ttl|DateInterval|int|string|null $ttl = null,
    ): int;

    /**
     * @param Key|string $key
     * @param int $amount
     * @param ?int $initial
     * @param Ttl|DateInterval|string|int|null $ttl
     * @return int
     * @throws \Silviooosilva\CacheerPhp\Exceptions\UnsupportedCapabilityException
     */
    public function decrement(
        string|Key $key,
        int $amount = 1,
        ?int $initial = null,
        Ttl|DateInterval|int|string|null $ttl = null,
    ): int;

    /**
     * Extend an entry's lifetime without rewriting its value. False when the key
     * is absent.
     *
     * @param Key|string $key
     * @param Ttl|DateInterval|string|int $ttl
     * @return bool
     * @throws \Silviooosilva\CacheerPhp\Exceptions\UnsupportedCapabilityException
     */
    public function touch(string|Key $key, Ttl|DateInterval|int|string $ttl): bool;

    /**
     * Associate a key with one or more tags for later bulk invalidation.
     *
     * @param Key|string $key
     * @param string ...$tags
     * @throws \Silviooosilva\CacheerPhp\Exceptions\UnsupportedCapabilityException
     */
    public function tag(string|Key $key, string ...$tags): void;

    /**
     * Delete every key carrying the tag; returns how many were removed.
     *
     * @param string $tag
     * @return int
     * @throws \Silviooosilva\CacheerPhp\Exceptions\UnsupportedCapabilityException
     */
    public function flushTag(string $tag): int;

    /**
     * A named cross-process mutex, namespaced by this cache's scope.
     *
     * @param string $name
     * @param Ttl|DateInterval|string|int $ttl
     * @return Lock
     * @throws \Silviooosilva\CacheerPhp\Exceptions\UnsupportedCapabilityException
     */
    public function lock(string $name, Ttl|DateInterval|int|string $ttl = 60): Lock;

    /**
     * Every live entry in this cache's scope — metadata included.
     *
     * @return iterable<CacheEntry>
     * @throws \Silviooosilva\CacheerPhp\Exceptions\UnsupportedCapabilityException
     */
    public function entries(): iterable;

    /**
     * Drop expired entries eagerly; returns how many were removed.
     *
     * @return int
     * @throws \Silviooosilva\CacheerPhp\Exceptions\UnsupportedCapabilityException
     */
    public function prune(): int;

    /**
     * An isolated keyspace within this cache. Nests, and can be cleared alone.
     *
     * @param Scope|string $scope
     * @return static
     */
    public function scope(string|Scope $scope): static;

    /**
     * The scope this cache is bound to; {@see Scope::root()} when unscoped.
     *
     * @return Scope
     */
    public function boundScope(): Scope;

    /**
     * Alias of {@see self::scope()} — `$cache->in('billing')->get('invoice')`.
     *
     * @param Scope|string $scope
     * @return static
     */
    public function in(string|Scope $scope): static;

    /**
     * Bind a default TTL, jitter, negative caching, and serve-stale-on-error.
     *
     * @param CachePolicy $policy
     * @return static
     */
    public function withPolicy(CachePolicy $policy): static;

    /**
     * A read-formatting view: reads return a CacheDataFormatter you can chain
     * `->toJson()` / `->toArray()` / `->toObject()` on.
     *
     * @return FormattedCacheer
     */
    public function formatted(): FormattedCacheer;

    /**
     * What this cache is: store, scope, capabilities, policy. For health
     * endpoints, logs, and debugging — never contains cached values.
     *
     * @return array{store: string, scope: string, policy: bool, capabilities: array<string, bool>}
     */
    public function stats(): array;
}
