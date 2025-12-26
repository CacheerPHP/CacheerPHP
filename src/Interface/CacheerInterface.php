<?php

namespace Silviooosilva\CacheerPhp\Interface;

/**
 * Class CacheerInterface
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
     * @param string $namespace Namespace for organization
     * @return mixed True on success, false on failure
     */
    public function appendCache(string $cacheKey, mixed $cacheData, string $namespace = '');
    
    /**
     * Clears a specific cache item.
     *
     * @param string $cacheKey Unique item key
     * @param string $namespace Namespace for organization
     * @return void
     */
    public function clearCache(string $cacheKey, string $namespace = '');
    
    /**
     * Flushes all cache items.
     *
     * @return void
     */
    public function flushCache();

    /**
     * Gets all items in a specific namespace.
     *
     * @param string $namespace Namespace for organization
     * @return CacheDataFormatter|mixed Returns a formatter with all items in the namespace
     */
    public function getAll(string $namespace = '');

    /**
     * Retrieves a single cache item.
     *
     * @param string $cacheKey Unique item key
     * @param string $namespace Namespace for organization
     * @param string|int $ttl Lifetime in seconds (default: 3600)
     * @return mixed Returns the cached data or null if not found
     */
    public function getCache(string $cacheKey, string $namespace = '', string|int $ttl = 3600);

    /**
     * Retrieves multiple cache items by their keys.
     *
     * @param array $cacheKeys Array of item keys
     * @param string $namespace Namespace for organization
     * @param string|int $ttl Lifetime in seconds (default: 3600)
     * @return CacheDataFormatter|mixed Returns a formatter with the retrieved items
     */
    public function getMany(array $cacheKeys, string $namespace = '', string|int $ttl = 3600);

    /**
     * Checks if a cache item exists.
     *
     * @param string $cacheKey Unique item key
     * @param string $namespace Namespace for organization
     * @return bool True if the item exists, false otherwise
     */
    public function has(string $cacheKey, string $namespace = ''): bool;

    /**
     * Stores an item in the cache with a specific TTL.
     *
     * @param string $cacheKey Unique item key
     * @param mixed $cacheData Data to be stored (serializable)
     * @param string $namespace Namespace for organization
     * @param string|int $ttl Lifetime in seconds (default: 3600)
     * @return bool True on success, false on failure
     */
    public function putCache(string $cacheKey, mixed $cacheData, string $namespace = '', int|string $ttl = 3600);

    /**
     * Stores multiple items in the cache.
     *
     * @param array $items Array of items to be stored, where keys are cache keys and values are cache data
     * @param string $namespace Namespace for organization
     * @param int $batchSize Number of items to store in each batch (default: 100)
     * @return bool True on success, false on failure
     */
    public function putMany(array $items, string $namespace = '', int $batchSize = 100);

    /**
     * Renews the cache for a specific key with a new TTL.
     *
     * @param string $cacheKey Unique item key
     * @param int|string $ttl Lifetime in seconds (default: 3600)
     * @param string $namespace Namespace for organization
     * @return bool True on success, false on failure
     */
    public function renewCache(string $cacheKey, int | string $ttl, string $namespace = '');

    /**
     * Associates one or more cache keys to a tag.
     *
     * @param string $tag
     * @param string ...$keys One or more cache keys
     * @return mixed
     */
    public function tag(string $tag, string ...$keys);

    /**
     * Flushes all cache items associated with a tag.
     *
     * @param string $tag
     * @return mixed
     */
    public function flushTag(string $tag);
}
