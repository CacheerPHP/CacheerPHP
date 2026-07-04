<?php

namespace Silviooosilva\CacheerPhp\Support;

use Closure;
use DateInterval;
use Silviooosilva\CacheerPhp\Cacheer;
use Silviooosilva\CacheerPhp\Enums\CacheTimeConstants;

/**
 * Class PendingCache
 *
 * @author Sílvio Silva <https://github.com/silviooosilva>
 * @package Silviooosilva\CacheerPhp
 */
final class PendingCache
{
    /**
     * @var Cacheer
     */
    private Cacheer $cacheer;

    /**
     * @var string
     */
    private string $namespace;

    /**
     * @param Cacheer $cacheer
     * @param string  $namespace Optional initial namespace.
     */
    public function __construct(Cacheer $cacheer, string $namespace = '')
    {
        $this->cacheer = $cacheer;
        $this->namespace = self::canonicalise($namespace);
    }

    /**
     * Return a new PendingCache scoped to $namespace.
     *
     * @param string $namespace
     * @return self
     */
    public function namespace(string $namespace): self
    {
        $next = self::canonicalise($namespace);
        $joined = $this->namespace === ''
            ? $next
            : ($next === '' ? $this->namespace : $this->namespace . '.' . $next);
        return new self($this->cacheer, $joined);
    }

    /**
     * Alias of namespace().
     *
     * @param string $namespace
     * @return self
     */
    public function in(string $namespace): self
    {
        return $this->namespace($namespace);
    }

    /**
     * Return a new PendingCache with the namespace cleared.
     *
     * @return self
     */
    public function withoutNamespace(): self
    {
        return new self($this->cacheer, '');
    }

    /**
     * @return string
     */
    public function getNamespace(): string
    {
        return $this->namespace;
    }

    /**
     * @return Cacheer
     */
    public function cacheer(): Cacheer
    {
        return $this->cacheer;
    }

    /**
     * Retrieve a cached item.
     *
     * @param string     $key
     * @param int|string $ttl
     * @return mixed
     */
    public function get(string $key, int|string $ttl = 3600): mixed
    {
        return $this->cacheer->getCache($key, $this->namespace, $ttl);
    }

    /**
     * Retrieve multiple cached items.
     *
     * @param array      $keys
     * @param int|string $ttl
     * @return mixed
     */
    public function getMany(array $keys, int|string $ttl = 3600): mixed
    {
        return $this->cacheer->getMany($keys, $this->namespace, $ttl);
    }

    /**
     * Store an item under the bound namespace.
     *
     * @param string                       $key
     * @param mixed                        $value
     * @param int|string|DateInterval|null $ttl
     * @return bool
     */
    public function put(string $key, mixed $value, int|string|DateInterval|null $ttl = 3600): bool
    {
        return $this->cacheer->putCache($key, $value, $this->namespace, $ttl);
    }

    /**
     * Store an item only if missing under the bound namespace.
     *
     * @param string                       $key
     * @param mixed                        $value
     * @param int|string|DateInterval|null $ttl
     * @return bool
     */
    public function add(string $key, mixed $value, int|string|DateInterval|null $ttl = 3600): bool
    {
        return $this->cacheer->add($key, $value, $this->namespace, $ttl);
    }

    /**
     * @param string $key
     * @return bool
     */
    public function has(string $key): bool
    {
        return $this->cacheer->has($key, $this->namespace);
    }

    /**
     * Inverse of has().
     *
     * @param string $key
     * @return bool
     */
    public function missing(string $key): bool
    {
        return !$this->has($key);
    }

    /**
     * Remove a cached item from the bound namespace.
     *
     * @param string $key
     * @return bool
     */
    public function forget(string $key): bool
    {
        return $this->cacheer->clearCache($key, $this->namespace);
    }

    /**
     * Retrieve and delete the item atomically.
     *
     * @param string $key
     * @return mixed
     */
    public function pull(string $key): mixed
    {
        return $this->cacheer->getAndForget($key, $this->namespace);
    }

    /**
     * Get-or-compute under the bound namespace.
     *
     * @param string                       $key
     * @param int|string|DateInterval|null $ttl
     * @param Closure                      $callback
     * @return mixed
     */
    public function remember(string $key, int|string|DateInterval|null $ttl, Closure $callback): mixed
    {
        return $this->cacheer->remember($key, $ttl, $callback, $this->namespace);
    }

    /**
     * Stale-while-revalidate get-or-compute under the bound namespace.
     *
     * @param string  $key
     * @param int     $fresh
     * @param int     $stale
     * @param Closure $callback
     * @return mixed
     */
    public function flexible(string $key, int $fresh, int $stale, Closure $callback): mixed
    {
        return $this->cacheer->flexible($key, $fresh, $stale, $callback, $this->namespace);
    }

    /**
     * Remember forever under the bound namespace.
     *
     * @param string  $key
     * @param Closure $callback
     * @return mixed
     */
    public function rememberForever(string $key, Closure $callback): mixed
    {
        return $this->remember($key, CacheTimeConstants::CACHE_FOREVER_TTL->value, $callback);
    }

    /**
     * @param string $namespace
     * @return string
     */
    private static function canonicalise(string $namespace): string
    {
        $trimmed = trim($namespace);
        if ($trimmed === '') {
            return '';
        }
        return trim($trimmed, '.');
    }
}
