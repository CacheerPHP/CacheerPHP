<?php

namespace Silviooosilva\CacheerPhp\CacheStore\Support;

/**
 * Handles namespace/key formatting and expiration checks for array cache.
 */
final class ArrayCacheKeyspace
{
    /**
     * @param string $cacheKey
     * @param string $namespace
     * @return string
     */
    public function build(string $cacheKey, string $namespace = ''): string
    {
        return $namespace !== '' ? ($namespace . ':' . $cacheKey) : $cacheKey;
    }

    /**
     * @param string $arrayStoreKey
     * @return array{0:string,1:string}
     */
    public function split(string $arrayStoreKey): array
    {
        $parts = explode(':', $arrayStoreKey, 2);
        if (count($parts) === 2) {
            return [$parts[0], $parts[1]];
        }
        return ['', $arrayStoreKey];
    }

    /**
     * @param array $cacheData
     * @return bool
     */
    public function isExpired(array $cacheData): bool
    {
        $expirationTime = $cacheData['expirationTime'] ?? 0;
        $now = time();
        return $expirationTime !== 0 && $now >= $expirationTime;
    }
}
