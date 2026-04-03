<?php

namespace Silviooosilva\CacheerPhp\Config\Option\Builder;

use Silviooosilva\CacheerPhp\CacheStore\CacheManager\OptionBuilders\DatabaseOptionBuilder;
use Silviooosilva\CacheerPhp\CacheStore\CacheManager\OptionBuilders\FileOptionBuilder;
use Silviooosilva\CacheerPhp\CacheStore\CacheManager\OptionBuilders\RedisOptionBuilder;

/**
 * Class OptionBuilder
 * @author Sílvio Silva <https://github.com/silviooosilva>
 * @package Silviooosilva\CacheerPhp
 */
class OptionBuilder
{
    /**
    * Creates a FileOptionBuilder instance for file-based cache options.
    *
    * @return FileOptionBuilder
    */
    public static function forFile(): FileOptionBuilder
    {
        return FileOptionBuilder::create();
    }

    /**
    * Creates a RedisOptionBuilder instance for Redis cache options.
    *
    * @return RedisOptionBuilder
    */
    public static function forRedis(): RedisOptionBuilder
    {
        return RedisOptionBuilder::create();
    }

    /**
    * Creates a DatabaseOptionBuilder instance for database cache options.
    *
    * @return DatabaseOptionBuilder
    */
    public static function forDatabase(): DatabaseOptionBuilder
    {
        return DatabaseOptionBuilder::create();
    }
}
