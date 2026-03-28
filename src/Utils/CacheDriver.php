<?php

namespace Silviooosilva\CacheerPhp\Utils;

use Silviooosilva\CacheerPhp\Cacheer;
use Silviooosilva\CacheerPhp\CacheStore\ArrayCacheStore;
use Silviooosilva\CacheerPhp\CacheStore\DatabaseCacheStore;
use Silviooosilva\CacheerPhp\CacheStore\FileCacheStore;
use Silviooosilva\CacheerPhp\CacheStore\RedisCacheStore;
use Silviooosilva\CacheerPhp\Exceptions\CacheFileException;
use Silviooosilva\CacheerPhp\Helpers\EnvHelper;

/**
 * Class CacheDriver
 *
 * Selects and initialises the cache backend (file, database, Redis, array).
 *
 * @author Sílvio Silva <https://github.com/silviooosilva>
 * @package Silviooosilva\CacheerPhp
 */
class CacheDriver
{
    /**
    * @var Cacheer
    */
    protected Cacheer $cacheer;

    /**
     * Path written to the log file used by each driver.
     *
     * @var string
     */
    public string $logPath = 'cacheer.log';

    /**
     * CacheDriver constructor.
     *
     * @param Cacheer $cacheer
     */
    public function __construct(Cacheer $cacheer)
    {
        $this->cacheer = $cacheer;
    }

    /**
     * Switches to the database-backed cache driver.
     *
     * @return Cacheer
     */
    public function useDatabaseDriver(): Cacheer
    {
        $this->cacheer->setCacheStore(new DatabaseCacheStore($this->logPath, $this->cacheer->getOptions()));
        return $this->cacheer;
    }

    /**
     * Switches to the filesystem-backed cache driver.
     *
     * Injects the logger path into the options array so FileCacheStore can
     * create its CacheLogger in the correct location.
     *
     * @return Cacheer
     */
    public function useFileDriver(): Cacheer
    {
        $this->cacheer->setOption('loggerPath', $this->logPath);
        $this->cacheer->setCacheStore(new FileCacheStore($this->cacheer->getOptions()));
        return $this->cacheer;
    }

    /**
     * Switches to the Redis-backed cache driver.
     *
     * @return Cacheer
     */
    public function useRedisDriver(): Cacheer
    {
        $this->cacheer->setCacheStore(new RedisCacheStore($this->logPath, $this->cacheer->getOptions()));
        return $this->cacheer;
    }

    /**
     * Switches to the in-memory array driver.
     *
     * Useful for testing or environments where persistence is not required.
     *
     * @return Cacheer
     */
    public function useArrayDriver(): Cacheer
    {
        $this->cacheer->setCacheStore(new ArrayCacheStore($this->logPath));
        return $this->cacheer;
    }

    /**
     * Selects the default (file) driver, auto-creating the cache directory
     * under the project root when none is provided in the options.
     *
     * @return Cacheer
     * @throws \Silviooosilva\CacheerPhp\Exceptions\CacheFileException
     */
    public function useDefaultDriver(): Cacheer
    {
        $option = $this->cacheer->getOption('cacheDir');

        if (!isset($option)) {
            $projectRoot = EnvHelper::getRootPath();
            $cacheDir = $projectRoot . DIRECTORY_SEPARATOR . 'CacheerPHP' . DIRECTORY_SEPARATOR . 'Cache';
            if ($this->isDir($cacheDir)) {
                $this->cacheer->setOption('cacheDir', $cacheDir);
            } else {
                throw CacheFileException::create('Failed to create cache directory: ' . $cacheDir);
            }
        }
        $this->useFileDriver();
        return $this->cacheer;
    }

    /**
    * Checks if the directory exists or creates it.
    *
    * @param mixed $dirName
    * @return bool
    */
    private function isDir(mixed $dirName): bool
    {
        if (is_dir($dirName)) {
            return true;
        }
        return mkdir($dirName, 0755, true);
    }
}
