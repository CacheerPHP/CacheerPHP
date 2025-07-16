<?php

namespace Silviooosilva\CacheerPhp\Helpers;

use Silviooosilva\CacheerPhp\Helpers\CacheerHelper;
use Silviooosilva\CacheerPhp\Exceptions\CacheDatabaseException;

/**
 * Class CacheDatabaseHelper
 * @author Sílvio Silva <https://github.com/silviooosilva>
 * @package Silviooosilva\CacheerPhp
 */
class CacheDatabaseHelper
{
    /**
     * Validates a cache item.
     * 
     * @param array $item
     * @return void
     */
    public static function validateCacheItem(array $item)
    {
        CacheerHelper::validateCacheItem(
            $item,
            fn($msg) => CacheDatabaseException::create($msg)
        );
    }

    /**
     * Merges cache data with existing data.
     * 
     * @param array $options
     * @return array
     */
    public static function mergeCacheData($cacheData)
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
    public static function arrayIdentifier(mixed $currentCacheData, mixed $cacheData)
    {
        return CacheerHelper::arrayIdentifier($currentCacheData, $cacheData);
    }
}

