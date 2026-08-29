<?php

declare(strict_types=1);

namespace Silviooosilva\CacheerPhp\Support;

use DateInterval;
use Silviooosilva\CacheerPhp\Config\CachePolicy;
use Silviooosilva\CacheerPhp\Contracts\Cache;
use Silviooosilva\CacheerPhp\Contracts\Lock;
use Silviooosilva\CacheerPhp\Kernel\CacheEntry;
use Silviooosilva\CacheerPhp\Kernel\Key;
use Silviooosilva\CacheerPhp\Kernel\Scope;
use Silviooosilva\CacheerPhp\Kernel\Ttl;

/**
 * A read-formatting view over a cache: value-returning reads hand back a
 * {@see CacheDataFormatter} instead of the raw value, so you can chain
 * `->toJson()` / `->toArray()` / `->toObject()` / `->toString()`.
 *
 *     $cache = Cacheer::file('/var/cache')->formatted();
 *     $json  = $cache->get('user:1')->toJson();
 *
 * The opt-in, immutable v6 equivalent of v5's useFormatter(). The underlying
 * `get()` is deliberately unchanged — it must return raw values (false, null,
 * ints) losslessly and feed the PSR adapters.
 *
 * Everything else forwards unchanged, so nothing is lost by reading through this
 * view: writes, batches, capabilities, scoping, and policies all still work, and
 * the views they return stay formatted. Call {@see self::raw()} to step back out.
 */
final readonly class FormattedCacheer
{
    /**
     * @param Cache $cache
     */
    public function __construct(private Cache $cache)
    {
    }

    // ------------------------------------------------------------------ read --

    /**
     * @param Key|string $key
     * @param mixed $default
     * @return CacheDataFormatter
     */
    public function get(string|Key $key, mixed $default = null): CacheDataFormatter
    {
        return new CacheDataFormatter($this->cache->get($key, $default));
    }

    /**
     * @param iterable<string|Key> $keys
     * @param mixed $default
     * @return CacheDataFormatter
     */
    public function many(iterable $keys, mixed $default = null): CacheDataFormatter
    {
        return new CacheDataFormatter($this->cache->many($keys, $default));
    }

    /**
     * Metadata is not a value, so this is unformatted by design.
     *
     * @param Key|string $key
     * @return CacheEntry
     */
    public function entry(string|Key $key): CacheEntry
    {
        return $this->cache->entry($key);
    }

    /**
     * @param Key|string $key
     * @return bool
     */
    public function has(string|Key $key): bool
    {
        return $this->cache->has($key);
    }

    /**
     * @param Key|string $key
     * @return bool
     */
    public function missing(string|Key $key): bool
    {
        return $this->cache->missing($key);
    }

    // ----------------------------------------------------------------- write --

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
        $this->cache->set($key, $value, $ttl);
    }

    /**
     * @param Key|string $key
     * @param mixed $value
     */
    public function forever(string|Key $key, mixed $value): void
    {
        $this->cache->forever($key, $value);
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
        return $this->cache->add($key, $value, $ttl);
    }

    /**
     * @param iterable<array-key, mixed> $values
     * @param Ttl|DateInterval|string|int|null $ttl
     */
    public function setMany(
        iterable $values,
        Ttl|DateInterval|int|string|null $ttl = null,
    ): void {
        $this->cache->setMany($values, $ttl);
    }

    /**
     * @param Key|string $key
     * @return bool
     */
    public function delete(string|Key $key): bool
    {
        return $this->cache->delete($key);
    }

    /**
     * @param Key|string $key
     * @param mixed $default
     * @return CacheDataFormatter
     */
    public function pull(string|Key $key, mixed $default = null): CacheDataFormatter
    {
        return new CacheDataFormatter($this->cache->pull($key, $default));
    }

    /**
     * @param iterable<string|Key> $keys
     * @return bool
     */
    public function deleteMany(iterable $keys): bool
    {
        return $this->cache->deleteMany($keys);
    }

    public function clear(): void
    {
        $this->cache->clear();
    }

    // --------------------------------------------------------------- compute --

    /**
     * @param Key|string $key
     * @param Ttl|DateInterval|string|int|null $ttl
     * @param callable $callback
     * @return CacheDataFormatter
     */
    public function remember(
        string|Key $key,
        Ttl|DateInterval|int|string|null $ttl,
        callable $callback,
    ): CacheDataFormatter {
        return new CacheDataFormatter($this->cache->remember($key, $ttl, $callback));
    }

    /**
     * @param Key|string $key
     * @param callable $callback
     * @return CacheDataFormatter
     */
    public function rememberForever(string|Key $key, callable $callback): CacheDataFormatter
    {
        return new CacheDataFormatter($this->cache->rememberForever($key, $callback));
    }

    /**
     * @param Key|string $key
     * @param int $fresh
     * @param int $stale
     * @param callable $callback
     * @return CacheDataFormatter
     */
    public function flexible(string|Key $key, int $fresh, int $stale, callable $callback): CacheDataFormatter
    {
        return new CacheDataFormatter($this->cache->flexible($key, $fresh, $stale, $callback));
    }

    // ---------------------------------------------------------- capabilities --

    /**
     * @param class-string $capability
     * @return bool
     */
    public function supports(string $capability): bool
    {
        return $this->cache->supports($capability);
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
        return $this->cache->increment($key, $amount, $initial, $ttl);
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
        return $this->cache->decrement($key, $amount, $initial, $ttl);
    }

    /**
     * @param Key|string $key
     * @param Ttl|DateInterval|string|int $ttl
     * @return bool
     */
    public function touch(string|Key $key, Ttl|DateInterval|int|string $ttl): bool
    {
        return $this->cache->touch($key, $ttl);
    }

    /**
     * @param Key|string $key
     * @param string ...$tags
     */
    public function tag(string|Key $key, string ...$tags): void
    {
        $this->cache->tag($key, ...$tags);
    }

    /**
     * @param string $tag
     * @return int
     */
    public function flushTag(string $tag): int
    {
        return $this->cache->flushTag($tag);
    }

    /**
     * @param string $name
     * @param Ttl|DateInterval|string|int $ttl
     * @return Lock
     */
    public function lock(string $name, Ttl|DateInterval|int|string $ttl = 60): Lock
    {
        return $this->cache->lock($name, $ttl);
    }

    /**
     * @return iterable<CacheEntry>
     */
    public function entries(): iterable
    {
        return $this->cache->entries();
    }

    /**
     * @return int
     */
    public function prune(): int
    {
        return $this->cache->prune();
    }

    // ----------------------------------------------------------------- views --

    /**
     * @param Scope|string $scope
     * @return FormattedCacheer
     */
    public function scope(string|Scope $scope): self
    {
        return new self($this->cache->scope($scope));
    }

    /**
     * @param Scope|string $scope
     * @return FormattedCacheer
     */
    public function in(string|Scope $scope): self
    {
        return new self($this->cache->in($scope));
    }

    /**
     * @return Scope
     */
    public function boundScope(): Scope
    {
        return $this->cache->boundScope();
    }

    /**
     * @param CachePolicy $policy
     * @return FormattedCacheer
     */
    public function withPolicy(CachePolicy $policy): self
    {
        return new self($this->cache->withPolicy($policy));
    }

    /**
     * @return array{store: string, scope: string, policy: bool, capabilities: array<string, bool>}
     */
    public function stats(): array
    {
        return $this->cache->stats();
    }

    /**
     * Already formatted — returns this view, so the call is idempotent and the
     * surface stays complete wherever a Cache is expected.
     *
     * @return FormattedCacheer
     */
    public function formatted(): self
    {
        return $this;
    }

    /**
     * The underlying, unformatted cache.
     *
     * @return Cache
     */
    public function raw(): Cache
    {
        return $this->cache;
    }
}
