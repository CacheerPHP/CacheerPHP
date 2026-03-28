<?php

namespace Silviooosilva\CacheerPhp\CacheStore\CacheManager;

use Predis\Autoloader;
use Predis\Client;

/**
 * Class RedisCacheManager
 * @author Sílvio Silva <https://github.com/silviooosilva>
 * @package Silviooosilva\CacheerPhp
 */
class RedisCacheManager
{
    /**
     * @var Predis\Client
     */
    private static $redis;

    /**
     * @param string $namespace
     */
    private static $namespace;

    /**
     * Connects to the Redis server using the configuration defined in REDIS_CONNECTION_CONFIG.
     *
    * @return Client
    */
    public static function connect()
    {
        Autoloader::register();
        self::$redis = new Client([
          'scheme'   => 'tcp',
          'host'     => REDIS_CONNECTION_CONFIG['REDIS_HOST'],
          'port'     => REDIS_CONNECTION_CONFIG['REDIS_PORT'],
          'password' => REDIS_CONNECTION_CONFIG['REDIS_PASSWORD'],
          'database' => 0,
        ]);
        self::auth();
        self::$namespace = REDIS_CONNECTION_CONFIG['REDIS_NAMESPACE'] ?? 'Cache';
        return self::$redis;
    }

    /**
    * Authenticates the Redis connection if a password is provided in the configuration.
    *
    * @return void
    */
    private static function auth(): void
    {
        if (is_string(REDIS_CONNECTION_CONFIG['REDIS_PASSWORD']) && REDIS_CONNECTION_CONFIG['REDIS_PASSWORD'] !== '') {
            self::$redis->auth(REDIS_CONNECTION_CONFIG['REDIS_PASSWORD']);
        }
    }

}
