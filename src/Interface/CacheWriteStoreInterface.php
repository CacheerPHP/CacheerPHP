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
     * @return bool True on success, false if the key does not exist or append failed.
     */
    public function appendCache(string $cacheKey, mixed $cacheData, string $namespace = ''): bool;

    /**
     * Clears a specific cache entry.
     *
     * @param string $cacheKey
     * @param string $namespace
     * @return void
     */
    public function clearCache(string $cacheKey, string $namespace = ''): void;

    /**
     * Flushes all cache entries.
     *
     * @return void
     */
    public function flushCache(): void;

    /**
     * Stores data in the cache.
     *
     * @param string $cacheKey
     * @param mixed $cacheData
     * @param string $namespace
     * @param int|string $ttl Lifetime in seconds (or a human-readable string like "1 hour").
     * @return bool True on success, false on failure.
     */
    public function putCache(string $cacheKey, mixed $cacheData, string $namespace = '', int|string $ttl = 3600): bool;

    /**
     * Stores multiple items in the cache.
     *
     * @param array $items Array of ['cacheKey' => ..., 'cacheData' => ...] entries.
     * @param string $namespace
     * @param int $batchSize
     * @return void
     */
    public function putMany(array $items, string $namespace = '', int $batchSize = 100): void;

    /**
     * Renews the TTL of a cache entry.
     *
     * @param string $cacheKey
     * @param int|string $ttl
     * @param string $namespace
     * @return void
     */
    public function renewCache(string $cacheKey, int|string $ttl, string $namespace = ''): void;

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
