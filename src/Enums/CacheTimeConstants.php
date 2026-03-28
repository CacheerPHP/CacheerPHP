<?php

namespace Silviooosilva\CacheerPhp\Enums;

enum CacheTimeConstants: int
{
    /**
     * TTL value for storing cache items indefinitely (forever).
     * Uses PHP_INT_MAX to avoid 32-bit integer overflow that
     * the previous 31536000 * 1000 literal caused.
     */
    case CACHE_FOREVER_TTL = PHP_INT_MAX;

    /**
     * Time units in seconds.
     */
    case SECOND = 1;
    case MINUTE = 60;
    case HOUR = 3600;
    case DAY = 86400;
    case WEEK = 604800;
    case MONTH = 2592000;
    case YEAR = 31536000;
}
