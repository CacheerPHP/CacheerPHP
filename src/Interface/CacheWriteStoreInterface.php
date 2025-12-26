<?php

namespace Silviooosilva\CacheerPhp\Interface;

interface CacheWriteStoreInterface
{   
    /**
     * Appends data to an existing cache entry.
     * 
     * @param string $cacheKey
     * @param mixed $cacheData
     * @param string $namespace
     * @return void
     */
    public function appendCache(string $cacheKey, mixed $cacheData, string $namespace = '');

    /**
     * Clears a specific cache entry.
     * 
     * @param string $cacheKey
     * @param string $namespace
     * @return void
     */
    public function clearCache(string $cacheKey, string $namespace = '');

    /**
     * Flushes all cache entries.
     * 
     * @return void
     */
    public function flushCache();

    /**
     * Stores data in the cache.
     * 
     * @param string $cacheKey
     * @param mixed $cacheData
     * @param string $namespace
     * @param int|string $ttl
     * @return mixed
     */
    public function putCache(string $cacheKey, mixed $cacheData, string $namespace = '', int|string $ttl = 3600);

    /**
     * Stores multiple items in the cache.
     * 
     * @param array $items
     * @param string $namespace
     * @param int $batchSize
     * @return void
     */
    public function putMany(array $items, string $namespace = '', int $batchSize = 100);

    /**
     * Renews the TTL of a cache entry.
     * 
     * @param string $cacheKey
     * @param int|string $ttl
     * @param string $namespace
     * @return mixed
     */
    public function renewCache(string $cacheKey, int|string $ttl, string $namespace = '');

    /**
     * Retrieves the last operation message.
     * 
     * @return string
     */
    public function getMessage(): string;

    /**
     * Checks if the last operation was successful.
     * 
     * @return bool
     */
    public function isSuccess(): bool;
}
