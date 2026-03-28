<?php

namespace Silviooosilva\CacheerPhp\Helpers;

/**
 * Class CacheFileHelper
 * @author Sílvio Silva <https://github.com/silviooosilva>
 * @package Silviooosilva\CacheerPhp
 */
class CacheFileHelper
{
    /**
     * Merges cache data with existing data.
     *
     * @param $cacheData
     * @return array
     */
    public static function mergeCacheData($cacheData): array
    {
        return CacheerHelper::mergeCacheData($cacheData);
    }

    public static function ttl($ttl = null, ?int $defaultTTL = null): mixed
    {
        if ($ttl) {
            $ttl = is_string($ttl) ? CacheerHelper::convertExpirationToSeconds($ttl) : $ttl;
        } else {
            $ttl = $defaultTTL;
        }
        return $ttl;
    }

    /**
    * Generates an array identifier for cache data.
    *
    * @param mixed $currentCacheData
    * @param mixed $cacheData
    * @return array
    */
    public static function arrayIdentifier(mixed $currentCacheData, mixed $cacheData): array
    {
        return CacheerHelper::arrayIdentifier($currentCacheData, $cacheData);
    }
}
