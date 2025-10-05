<?php

namespace Silviooosilva\CacheerPhp\Helpers;

use Silviooosilva\CacheerPhp\Helpers\CacheerHelper;
use Silviooosilva\CacheerPhp\Exceptions\CacheFileException;
use Silviooosilva\CacheerPhp\Enums\CacheTimeConstants;

/**
 * Class CacheFileHelper
 * @author Sílvio Silva <https://github.com/silviooosilva>
 * @package Silviooosilva\CacheerPhp
 */
class CacheFileHelper
{

    /**
     * Converts a string expiration format to seconds.
     *
     * @param string $expiration
     * @return float|int
     * @throws CacheFileException
     */
    public static function convertExpirationToSeconds(string $expiration): float|int
    {
        $units = [
            'second' => CacheTimeConstants::SECOND->value,
            'minute' => CacheTimeConstants::MINUTE->value,
            'hour'   => CacheTimeConstants::HOUR->value,
            'day'    => CacheTimeConstants::DAY->value,
            'week'   => CacheTimeConstants::WEEK->value,
            'month'  => CacheTimeConstants::MONTH->value,
            'year'   => CacheTimeConstants::YEAR->value,
        ];
        foreach ($units as $unit => $value) {
            if (str_contains($expiration, $unit)) {
                return (int)$expiration * $value;
            }
        }
        throw CacheFileException::create("Invalid expiration format");
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
     * Validates a cache item.
     * 
     * @param array $item
     * @return void
     */
    public static function validateCacheItem(array $item): void
    {
        CacheerHelper::validateCacheItem(
            $item,
            fn($msg) => CacheFileException::create($msg)
        );
    }

    /**
     * Calculates the TTL (Time To Live) for cache items.
     *
     * @param null $ttl
     * @param int|null $defaultTTL
     * @return mixed
     * @throws CacheFileException
     */
    public static function ttl($ttl = null, ?int $defaultTTL = null): mixed
    {
        if ($ttl) {
            $ttl = is_string($ttl) ? self::convertExpirationToSeconds($ttl) : $ttl;
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
