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
     * @return mixed
     */
    public function getCache(string $cacheKey, string $namespace = '', string|int $ttl = 3600);

    /**
     * Retrieves multiple cache items by their keys.
     * 
     * @param array $cacheKeys
     * @param string $namespace
     * @param string|int $ttl
     * @return mixed
     */
    public function getMany(array $cacheKeys, string $namespace = '', string|int $ttl = 3600);

    /**
     * Gets all items in a specific namespace.
     * 
     * @param string $namespace
     * @return mixed
     */
    public function getAll(string $namespace = '');

    /**
     * Checks if a cache item exists.
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
