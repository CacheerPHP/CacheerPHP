<?php

namespace Silviooosilva\CacheerPhp\Interface;

interface CacheReadStoreInterface
{
    /**
     * Retrieves data from the cache.
     *
     * @param string $cacheKey
     * @param string $namespace
     * @param string|int $ttl
     * @return mixed Returns the cached value, or null on miss/expiry.
     */
    public function getCache(string $cacheKey, string $namespace = '', string|int $ttl = 3600): mixed;

    /**
     * Retrieves multiple cache items by their keys.
     *
     * @param array $cacheKeys
     * @param string $namespace
     * @param string|int $ttl
     * @return array Keys present in cache are included; missing keys are omitted.
     */
    public function getMany(array $cacheKeys, string $namespace = '', string|int $ttl = 3600): array;

    /**
     * Gets all items in a specific namespace.
     *
     * @param string $namespace
     * @return array
     */
    public function getAll(string $namespace = ''): array;

    /**
     * Checks if a cache item exists and has not expired.
     *
     * @param string $cacheKey
     * @param string $namespace
     * @return bool
     */
    public function has(string $cacheKey, string $namespace = ''): bool;

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
