<?php

namespace Silviooosilva\CacheerPhp\Helpers;

use Silviooosilva\CacheerPhp\Cacheer;
use Silviooosilva\CacheerPhp\CacheStore\ArrayCacheStore;
use Silviooosilva\CacheerPhp\CacheStore\FileCacheStore;
use Silviooosilva\CacheerPhp\CacheStore\RedisCacheStore;
use Silviooosilva\CacheerPhp\Core\Connect;
use Silviooosilva\CacheerPhp\Enums\DatabaseDriver;
use Silviooosilva\CacheerPhp\Exceptions\ConnectionException;
use Silviooosilva\CacheerPhp\Utils\CacheDriver;

/**
 * Class CacheConfig
 * @author Sílvio Silva <https://github.com/silviooosilva>
 * @package Silviooosilva\CacheerPhp
 */
class CacheConfig
{
    /**
     * @var Cacheer
     */
    protected Cacheer $cacheer;

    /**
     * CacheConfig constructor.
     *
     * @param Cacheer $cacheer
     */
    public function __construct(Cacheer $cacheer)
    {
        $this->cacheer = $cacheer;
        $this->setTimeZone(date_default_timezone_get());
    }

    /**
     * Sets the default timezone for the application.
     *
     * @param string $timezone
     * @return $this
     */
    public function setTimeZone(string $timezone): CacheConfig
    {
        if (in_array($timezone, timezone_identifiers_list())) {
            date_default_timezone_set($timezone);
        }
        return $this;
    }

    /**
     * Sets the cache driver for the application.
     *
     * @return CacheDriver
     */
    public function setDriver(): CacheDriver
    {
        return new CacheDriver($this->cacheer);
    }

    /**
     * Sets the logger path for the cache driver.
     *
     * @param string $path
     * @return Cacheer
     */
    public function setLoggerPath(string $path): Cacheer
    {

        $cacheDriver = $this->setDriver();
        $cacheDriver->logPath = $path;

        $cacheDriverInstance = $this->cacheer->getCacheStore();

        return match (get_class($cacheDriverInstance)) {
            FileCacheStore::class  => $cacheDriver->useFileDriver(),
            RedisCacheStore::class => $cacheDriver->useRedisDriver(),
            ArrayCacheStore::class => $cacheDriver->useArrayDriver(),
            default                => $cacheDriver->useDatabaseDriver(),
        };
    }

    /**
     * Sets the database connection type for the application.
     *
     * @param DatabaseDriver|string $driver
     * @return void
     * @throws ConnectionException
     */
    public function setDatabaseConnection(DatabaseDriver|string $driver): void
    {
        Connect::setConnection($driver);
    }

    /**
     * Replaces all options on the Cacheer instance.
     *
     * @param array $options
     * @return void
     */
    public function setUp(array $options): void
    {
        $this->cacheer->setOptions($options);
    }

    /**
     * Returns the value of a single configuration option, or a default if the key is not set.
     *
     * @param string $key
     * @param mixed  $default
     * @return mixed
    */
    public function getOption(string $key, mixed $default = null): mixed
    {
        return $this->cacheer->getOption($key, $default);
    }

    /**
     * Gets the options from the Cacheer instance.
     *
     * @return array
     */
    public function getOptions(): array
    {
        return $this->cacheer->getOptions();
    }
}
