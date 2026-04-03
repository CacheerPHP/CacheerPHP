<?php

namespace Silviooosilva\CacheerPhp\Helpers;

/**
 * Class CacheRedisHelper
 * @author Sílvio Silva <https://github.com/silviooosilva>
 * @package Silviooosilva\CacheerPhp
 */
class CacheRedisHelper
{
    /**
    * serializes or unserializes data based on the $serialize flag.
    *
    * @param mixed $data
    * @param bool  $serialize
    * @return mixed
    */
    public static function serialize(mixed $data, bool $serialize = true): mixed
    {
        if ($serialize) {
            return serialize($data);
        }

        return unserialize($data);

    }

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
