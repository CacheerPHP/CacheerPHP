<?php

namespace Silviooosilva\CacheerPhp\Enums;

enum CacheTimeConstants: int
{
    /**
     * TTL value for storing cache items indefinitely (forever).
     */
    case CACHE_FOREVER_TTL = 31536000 * 1000;

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