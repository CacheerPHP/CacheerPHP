<?php

namespace Silviooosilva\CacheerPhp\Interface;

/**
 * Class CacheerInterface
 *
 * @author Sílvio Silva <https://github.com/silviooosilva>
 * @package Silviooosilva\CacheerPhp
 */
interface CacheerInterface extends CacheReadStoreInterface, CacheWriteStoreInterface, TaggableCacheStoreInterface
{
    /**
     * Appends data to an existing cache item.
     *
     * @param string $cacheKey Unique item key
     * @param mixed $cacheData Data to be appended (serializable)
     * @param string $namespace Namespace for organisation
     * @return bool True on success, false if key absent or append failed.
     */
    public function appendCache(string $cacheKey, mixed $cacheData, string $namespace = ''): bool;

    /**
     * Clears a specific cache item.
     *
     * @param string $cacheKey Unique item key
     * @param string $namespace Namespace for organisation
     * @return void
     */
    public function clearCache(string $cacheKey, string $namespace = ''): void;

    /**
     * Flushes all cache items.
     *
     * @return void
     */
    public function flushCache(): void;

    /**
     * Gets all items in a specific namespace.
     *
     * @param string $namespace Namespace for organisation
     * @return array All items stored under the namespace.
     */
    public function getAll(string $namespace = ''): array;

    /**
     * Retrieves a single cache item.
     *
     * @param string $cacheKey Unique item key
     * @param string $namespace Namespace for organisation
     * @param string|int $ttl Lifetime in seconds (default: 3600)
     * @return mixed Cached data or null if not found / expired.
     */
    public function getCache(string $cacheKey, string $namespace = '', string|int $ttl = 3600): mixed;

    /**
     * Retrieves multiple cache items by their keys.
     *
     * @param array $cacheKeys Array of item keys
     * @param string $namespace Namespace for organisation
     * @param string|int $ttl Lifetime in seconds (default: 3600)
     * @return array Map of key => value for all found items.
     */
    public function getMany(array $cacheKeys, string $namespace = '', string|int $ttl = 3600): array;

    /**
     * Checks if a cache item exists and has not expired.
     *
     * @param string $cacheKey Unique item key
     * @param string $namespace Namespace for organisation
     * @return bool
     */
    public function has(string $cacheKey, string $namespace = ''): bool;

    /**
     * Stores an item in the cache with a specific TTL.
     *
     * @param string $cacheKey Unique item key
     * @param mixed $cacheData Data to be stored (serializable)
     * @param string $namespace Namespace for organisation
     * @param string|int $ttl Lifetime in seconds (default: 3600)
     * @return bool True on success, false on failure.
     */
    public function putCache(string $cacheKey, mixed $cacheData, string $namespace = '', int|string $ttl = 3600): bool;

    /**
     * Stores multiple items in the cache.
     *
     * @param array $items Array of ['cacheKey' => ..., 'cacheData' => ...] entries.
     * @param string $namespace Namespace for organisation
     * @param int $batchSize Number of items to store per batch (default: 100)
     * @return void
     */
    public function putMany(array $items, string $namespace = '', int $batchSize = 100): void;

    /**
     * Renews the cache for a specific key with a new TTL.
     *
     * @param string $cacheKey Unique item key
     * @param int|string $ttl Lifetime in seconds (default: 3600)
     * @param string $namespace Namespace for organisation
     * @return void
     */
    public function renewCache(string $cacheKey, int|string $ttl, string $namespace = ''): void;

    /**
     * Associates one or more cache keys to a tag.
     *
     * @param string $tag
     * @param string ...$keys One or more cache keys
     * @return bool
     */
    public function tag(string $tag, string ...$keys): bool;

    /**
     * Flushes all cache items associated with a tag.
     *
     * @param string $tag
     * @return void
     */
    public function flushTag(string $tag): void;
}
