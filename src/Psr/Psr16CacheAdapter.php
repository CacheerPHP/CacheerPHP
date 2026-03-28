<?php

namespace Silviooosilva\CacheerPhp\Psr;

use DateInterval;
use DateTimeImmutable;
use Psr\SimpleCache\CacheInterface;
use Silviooosilva\CacheerPhp\Cacheer;
use Silviooosilva\CacheerPhp\Enums\CacheTimeConstants;
use Silviooosilva\CacheerPhp\Exceptions\CacheInvalidArgumentException;
use Silviooosilva\CacheerPhp\Helpers\CacheerHelper;

/**
 * Class Psr16CacheAdapter
 *
 * A PSR-16 (SimpleCache) compliant adapter that wraps a Cacheer instance.
 *
 * This adapter allows CacheerPHP to be used wherever a standard
 * \Psr\SimpleCache\CacheInterface is expected (e.g. framework integrations,
 * third-party libraries). All cache operations are delegated to the underlying
 * Cacheer instance; key validation and TTL normalisation follow the PSR-16
 * specification.
 *
 * PSR-16 key rules enforced:
 *  - Must not be an empty string.
 *  - Must not contain any of the reserved characters: {}()/\@:
 *
 * TTL handling:
 *  - int   → seconds; 0 or negative means "expire immediately / do not cache"
 *  - \DateInterval → converted to seconds
 *  - null  → store forever (PHP_INT_MAX seconds)
 *
 * @author Sílvio Silva <https://github.com/silviooosilva>
 * @package Silviooosilva\CacheerPhp
 */
class Psr16CacheAdapter implements CacheInterface
{
    /**
     * Psr16CacheAdapter constructor.
     *
     * @param Cacheer $cache     The underlying Cacheer instance to delegate to.
     * @param string  $namespace Optional namespace applied to every cache key.
     */
    public function __construct(
        private readonly Cacheer $cache,
        private readonly string $namespace = ''
    ) {}

    /**
     * Fetches a value from the cache.
     *
     * @param string $key     The unique key of this item in the cache.
     * @param mixed  $default Default value to return if the key does not exist.
     * @return mixed The value stored in the cache, or $default if not found.
     * @throws CacheInvalidArgumentException if the $key string is not valid.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $this->validateKey($key);

        $value = $this->cache->getCache($key, $this->namespace);

        return $this->cache->isSuccess() ? $value : $default;
    }

    /**
     * Persists data in the cache, uniquely referenced by a key.
     *
     * @param string                $key   The key under which to store the value.
     * @param mixed                 $value The value to store.
     * @param int|\DateInterval|null $ttl  Optional TTL. Null means store forever.
     * @return bool True on success, false on failure.
     * @throws CacheInvalidArgumentException if the $key string is not valid.
     */
    public function set(string $key, mixed $value, int|DateInterval|null $ttl = null): bool
    {
        $this->validateKey($key);

        $ttlSeconds = $this->normalizeTtl($ttl);

        // A TTL of 0 or negative means "do not cache / expire immediately".
        if ($ttlSeconds <= 0 && $ttl !== null) {
            $this->delete($key);
            return true;
        }

        return $this->cache->putCache($key, $value, $this->namespace, $ttlSeconds);
    }

    /**
     * Deletes an item from the cache by its unique key.
     *
     * @param string $key The unique cache key of the item to delete.
     * @return bool True if the item was successfully removed, false otherwise.
     * @throws CacheInvalidArgumentException if the $key string is not valid.
     */
    public function delete(string $key): bool
    {
        $this->validateKey($key);

        return $this->cache->clearCache($key, $this->namespace);
    }

    /**
     * Wipes the entire cache store.
     *
     * @return bool True on success, false on failure.
     */
    public function clear(): bool
    {
        return $this->cache->flushCache();
    }

    /**
     * Determines whether an item is present in the cache.
     *
     * @param string $key The cache item key.
     * @return bool
     * @throws CacheInvalidArgumentException if the $key string is not valid.
     */
    public function has(string $key): bool
    {
        $this->validateKey($key);

        return $this->cache->has($key, $this->namespace);
    }

    /**
     * Obtains multiple cache items by their unique keys.
     *
     * Keys that are not found are returned with the $default value.
     *
     * @param iterable<string> $keys    A list of key => default value pairs.
     * @param mixed            $default Default value for keys that do not exist.
     * @return iterable<string, mixed> A list of key => value pairs.
     * @throws CacheInvalidArgumentException if any of the $keys is not valid.
     */
    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $results = [];

        foreach ($keys as $key) {
            $this->validateKey($key);
            $value          = $this->cache->getCache($key, $this->namespace);
            $results[$key]  = $this->cache->isSuccess() ? $value : $default;
        }

        return $results;
    }

    /**
     * Persists a set of key => value pairs in the cache with an optional TTL.
     *
     * @param iterable<string, mixed> $values A list of key => value pairs to cache.
     * @param int|\DateInterval|null  $ttl    Optional TTL. Null means store forever.
     * @return bool True on success, false if any item failed to be stored.
     * @throws CacheInvalidArgumentException if any of the $values keys is not valid.
     */
    public function setMultiple(iterable $values, int|DateInterval|null $ttl = null): bool
    {
        $ttlSeconds = $this->normalizeTtl($ttl);
        $success    = true;

        foreach ($values as $key => $value) {
            $this->validateKey($key);

            if ($ttlSeconds <= 0 && $ttl !== null) {
                $this->delete($key);
                continue;
            }

            if (!$this->cache->putCache($key, $value, $this->namespace, $ttlSeconds)) {
                $success = false;
            }
        }

        return $success;
    }

    /**
     * Deletes multiple cache items in a single operation.
     *
     * @param iterable<string> $keys A list of string-based keys to be deleted.
     * @return bool True if all items were successfully removed, false otherwise.
     * @throws CacheInvalidArgumentException if any of the $keys is not valid.
     */
    public function deleteMultiple(iterable $keys): bool
    {
        $success = true;

        foreach ($keys as $key) {
            $this->validateKey($key);
            if (!$this->cache->clearCache($key, $this->namespace)) {
                $success = false;
            }
        }

        return $success;
    }

    /**
     * Validates a cache key against the PSR-16 specification.
     *
     * PSR-16 §1.1: A key is a string of at least one character that uniquely
     * identifies a cached item. It MUST NOT contain the characters {}()/\@:
     *
     * @param string $key
     * @return void
     * @throws CacheInvalidArgumentException
     */
    private function validateKey(string $key): void
    {
        CacheerHelper::validateKey($key);
    }

    /**
     * Normalises a PSR-16 TTL value to an integer number of seconds.
     *
     * @param int|\DateInterval|null $ttl
     * @return int
     */
    private function normalizeTtl(int|DateInterval|null $ttl): int
    {
        if ($ttl === null) {
            return CacheTimeConstants::CACHE_FOREVER_TTL->value;
        }

        if ($ttl instanceof DateInterval) {
            $now    = new DateTimeImmutable();
            $future = $now->add($ttl);
            return max(0, $future->getTimestamp() - $now->getTimestamp());
        }

        return $ttl;
    }
}
