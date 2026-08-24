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
    // ------------------------------------------------------------------ read --

    /**
     * The full entry — hit/miss, value, timestamps — rather than just the value.
     * The only way to distinguish a stored null from a miss.
     */
    public function entry(string|Key $key): CacheEntry;

    public function get(string|Key $key, mixed $default = null): mixed;

    public function has(string|Key $key): bool;

    /**
     * Inverse of {@see self::has()}; reads better in a guard clause.
     */
    public function missing(string|Key $key): bool;

    /**
     * @param iterable<string|Key> $keys
     * @return array<string, mixed>
     */
    public function many(iterable $keys, mixed $default = null): array;

    // ----------------------------------------------------------------- write --

    public function set(string|Key $key, mixed $value, Ttl|DateInterval|int|string|null $ttl = null): void;

    /**
     * Store with no expiry. Explicit about intent where `set($k, $v)` only
     * implies it.
     */
    public function forever(string|Key $key, mixed $value): void;

    /**
     * Store only if the key is absent; true when this call was the one that
     * stored it. Serialized by a lock when the store can lock, so it is a sound
     * first-writer-wins across processes; otherwise it is single-process only.
     */
    public function add(string|Key $key, mixed $value, Ttl|DateInterval|int|string|null $ttl = null): bool;

    /**
     * @param iterable<array-key, mixed> $values
     */
    public function setMany(iterable $values, Ttl|DateInterval|int|string|null $ttl = null): void;

    public function delete(string|Key $key): bool;

    /**
     * Read and delete in one call, returning the value the key held.
     */
    public function pull(string|Key $key, mixed $default = null): mixed;

    /**
     * @param iterable<string|Key> $keys
     */
    public function deleteMany(iterable $keys): bool;

    /**
     * Empty this cache — the whole store at root, or just this scope's keyspace
     * when scoped.
     */
    public function clear(): void;

    // --------------------------------------------------------------- compute --

    /**
     * Return the cached value, or compute, store, and return it. Single-flighted
     * across workers when the store can lock, so a miss on a hot key does not
     * stampede the origin.
     */
    public function remember(string|Key $key, Ttl|DateInterval|int|string|null $ttl, callable $callback): mixed;

    public function rememberForever(string|Key $key, callable $callback): mixed;

    /**
     * Stale-while-revalidate: serve fresh for $fresh seconds, then serve the
     * stale value while one worker refreshes it, until $stale seconds, after
     * which it is recomputed synchronously.
     */
    public function flexible(string|Key $key, int $fresh, int $stale, callable $callback): mixed;

    // ---------------------------------------------------------- capabilities --

    /**
     * Whether the underlying store really honors a capability interface — ask
     * before calling a method below if you support pluggable backends.
     *
     * @param class-string $capability
     */
    public function supports(string $capability): bool;

    /**
     * Atomically add to a counter and return its new value. With $initial set, a
     * missing key is created as ($initial + $amount).
     *
     * @throws \Silviooosilva\CacheerPhp\Exceptions\UnsupportedCapabilityException
     */
    public function increment(
        string|Key $key,
        int $amount = 1,
        ?int $initial = null,
        Ttl|DateInterval|int|string|null $ttl = null,
    ): int;

    /**
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
     * @throws \Silviooosilva\CacheerPhp\Exceptions\UnsupportedCapabilityException
     */
    public function touch(string|Key $key, Ttl|DateInterval|int|string $ttl): bool;

    /**
     * Associate a key with one or more tags for later bulk invalidation.
     *
     * @throws \Silviooosilva\CacheerPhp\Exceptions\UnsupportedCapabilityException
     */
    public function tag(string|Key $key, string ...$tags): void;

    /**
     * Delete every key carrying the tag; returns how many were removed.
     *
     * @throws \Silviooosilva\CacheerPhp\Exceptions\UnsupportedCapabilityException
     */
    public function flushTag(string $tag): int;

    /**
     * A named cross-process mutex, namespaced by this cache's scope.
     *
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
     * @throws \Silviooosilva\CacheerPhp\Exceptions\UnsupportedCapabilityException
     */
    public function prune(): int;

    // ----------------------------------------------------------------- views --

    /**
     * An isolated keyspace within this cache. Nests, and can be cleared alone.
     */
    public function scope(string|Scope $scope): static;

    /**
     * The scope this cache is bound to; {@see Scope::root()} when unscoped.
     */
    public function boundScope(): Scope;

    /**
     * Alias of {@see self::scope()} — `$cache->in('billing')->get('invoice')`.
     */
    public function in(string|Scope $scope): static;

    /**
     * Bind a default TTL, jitter, negative caching, and serve-stale-on-error.
     */
    public function withPolicy(CachePolicy $policy): static;

    /**
     * A read-formatting view: reads return a CacheDataFormatter you can chain
     * `->toJson()` / `->toArray()` / `->toObject()` on.
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
