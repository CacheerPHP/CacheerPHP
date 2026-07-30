<?php

declare(strict_types=1);

namespace Silviooosilva\CacheerPhp\Support;

use DateInterval;
use Silviooosilva\CacheerPhp\Cacheer;
use Silviooosilva\CacheerPhp\Kernel\CacheEntry;
use Silviooosilva\CacheerPhp\Kernel\Key;
use Silviooosilva\CacheerPhp\Kernel\Scope;
use Silviooosilva\CacheerPhp\Kernel\ScopedCacheer;
use Silviooosilva\CacheerPhp\Kernel\Ttl;

/**
 * A read-formatting view over a Cacheer: reads return a {@see CacheDataFormatter}
 * instead of the raw value, so you can chain `->toJson()` / `->toArray()` / etc.
 *
 *     $cache = Cacheer::file('/var/cache')->formatted();
 *     $json  = $cache->get('user:1')->toJson();
 *
 * This is the opt-in, immutable v6 equivalent of v5's useFormatter(). The base
 * Cacheer::get() is deliberately unchanged — it must return raw values (false,
 * null, ints) losslessly and feed the PSR adapters. Writes and existence checks
 * pass straight through; use raw() to get the underlying cache back.
 */
final readonly class FormattedCacheer
{
    public function __construct(private Cacheer|ScopedCacheer $cache)
    {
    }

    public function get(string|Key $key, mixed $default = null): CacheDataFormatter
    {
        return new CacheDataFormatter($this->cache->get($key, $default));
    }

    public function remember(
        string|Key $key,
        Ttl|DateInterval|int|string|null $ttl,
        callable $callback,
    ): CacheDataFormatter {
        return new CacheDataFormatter($this->cache->remember($key, $ttl, $callback));
    }

    public function entry(string|Key $key): CacheEntry
    {
        return $this->cache->entry($key);
    }

    public function set(
        string|Key $key,
        mixed $value,
        Ttl|DateInterval|int|string|null $ttl = null,
    ): void {
        $this->cache->set($key, $value, $ttl);
    }

    public function has(string|Key $key): bool
    {
        return $this->cache->has($key);
    }

    public function delete(string|Key $key): bool
    {
        return $this->cache->delete($key);
    }

    public function clear(): void
    {
        $this->cache->clear();
    }

    public function scope(string|Scope $scope): self
    {
        return new self($this->cache->scope($scope));
    }

    /**
     * The underlying, unformatted cache.
     */
    public function raw(): Cacheer|ScopedCacheer
    {
        return $this->cache;
    }
}
