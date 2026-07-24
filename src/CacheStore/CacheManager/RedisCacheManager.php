<?php

namespace Silviooosilva\CacheerPhp\CacheStore\CacheManager;

use Predis\Autoloader;
use Predis\Client;
use Silviooosilva\CacheerPhp\Boot\RuntimeConfig;

/**
 * Class RedisCacheManager
 * @author Sílvio Silva <https://github.com/silviooosilva>
 * @package Silviooosilva\CacheerPhp
 */
class RedisCacheManager
{
    /**
     * @var \Predis\Client
     */
    private static $redis;

    /**
     * Connects to the Redis server using the lazily resolved Redis configuration.
     *
    * @return Client
    */
    public static function connect()
    {
        $config = RuntimeConfig::redis();

        Autoloader::register();
        self::$redis = new Client([
          'scheme'   => 'tcp',
          'host'     => $config['host'],
          'port'     => $config['port'],
          'password' => $config['password'],
          'database' => $config['database'],
        ]);
        self::auth();
        return self::$redis;
    }

    /**
    * Authenticates the Redis connection if a password is provided in the configuration.
    *
    * @return void
    */
    private static function auth(): void
    {
        $password = RuntimeConfig::redis()['password'];
        if ($password !== '') {
            self::$redis->auth($password);
        }
    }

}
