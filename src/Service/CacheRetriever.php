<?php

namespace Silviooosilva\CacheerPhp\Service;

use Closure;
use Silviooosilva\CacheerPhp\Cacheer;
use Silviooosilva\CacheerPhp\Enums\CacheTimeConstants;
use Silviooosilva\CacheerPhp\Helpers\CacheerHelper;
use Silviooosilva\CacheerPhp\Utils\CacheDataFormatter;
use Silviooosilva\CacheerPhp\Exceptions\CacheFileException;

/**
* Class CacheRetriever
* @author Sílvio Silva <https://github.com/silviooosilva>
* @package Silviooosilva\CacheerPhp
*/
class CacheRetriever
{
    /**
    * @var Cacheer
    */
    private Cacheer $cacheer;

    /**
    * @var int
    */
    private int $foreverTTL = CacheTimeConstants::CACHE_FOREVER_TTL->value;

    /**
    * CacheRetriever constructor.
    *
    * @param Cacheer $cacheer
    */
    public function __construct(Cacheer $cacheer)
    {
        $this->cacheer = $cacheer;
    }

    /**
     * Retrieves a cache item by its key.
     *
     * @param string $cacheKey
     * @param string $namespace
     * @param int|string $ttl
     * @return mixed
     * @throws CacheFileException
     */
    public function getCache(string $cacheKey, string $namespace = '', int|string $ttl = 3600): mixed
    {
        $cacheData = $this->cacheer->cacheStore->getCache($cacheKey, $namespace, $ttl);
        $this->cacheer->syncState();

        if ($this->cacheer->isSuccess() && ($this->cacheer->isCompressionEnabled() ||   $this->cacheer->getEncryptionKey() !== null)) {
            $cacheData = CacheerHelper::recoverFromStorage($cacheData, $this->cacheer->isCompressionEnabled(), $this->cacheer->getEncryptionKey());
        }

        return $this->cacheer->isFormatted() ? new CacheDataFormatter($cacheData) : $cacheData;
    }

    /**
     * Retrieves multiple cache items by their keys.
     *
     * @param array $cacheKeys
     * @param string $namespace
     * @param int|string $ttl
     * @return array|CacheDataFormatter
     * @throws CacheFileException
     */
    public function getMany(array $cacheKeys, string $namespace = '', int|string $ttl = 3600): array|CacheDataFormatter
    {
        $cachedData = $this->cacheer->cacheStore->getMany($cacheKeys, $namespace, $ttl);
        return $this->getCachedDatum($cachedData);
    }

    /**
     * Retrieves all cache items in a namespace.
     *
     * @param string $namespace
     * @return CacheDataFormatter|mixed
     * @throws CacheFileException
     */
    public function getAll(string $namespace = ''): mixed
    {
        $cachedData = $this->cacheer->cacheStore->getAll($namespace);
        return $this->getCachedDatum($cachedData);
    }

    /**
     * Retrieves a cache item, deletes it, and returns its data.
     *
     * @param string $cacheKey
     * @param string $namespace
     * @return mixed|null
     * @throws CacheFileException
     */
    public function getAndForget(string $cacheKey, string $namespace = ''): mixed
    {
        $cachedData = $this->getCache($cacheKey, $namespace);

        if (!empty($cachedData)) {
            $this->cacheer->setInternalState("Cache retrieved and deleted successfully!", true);
            $this->cacheer->clearCache($cacheKey, $namespace);
            return $cachedData;
        }

        return null;
    }

    /**
     * Retrieves a cache item, or executes a callback to store it if not found.
     *
     * @param string $cacheKey
     * @param int|string $ttl
     * @param Closure $callback
     * @return mixed
     * @throws CacheFileException
     */
    public function remember(string $cacheKey, int|string $ttl, Closure $callback): mixed
    {
        $cachedData = $this->getCache($cacheKey, ttl: $ttl);

        if (!empty($cachedData)) {
            return $cachedData;
        }

        $cacheData = $callback();
        $this->cacheer->putCache($cacheKey, $cacheData, ttl: $ttl);
        return $cacheData;
    }

    /**
     * Retrieves a cache item indefinitely, or executes a callback to store it if not found.
     *
     * @param string $cacheKey
     * @param Closure $callback
     * @return mixed
     * @throws CacheFileException
     */
    public function rememberForever(string $cacheKey, Closure $callback): mixed
    {
        return $this->remember($cacheKey, $this->foreverTTL, $callback);
    }

    /**
     * Checks if a cache item exists.
     *
     * @param string $cacheKey
     * @param string $namespace
     * @return bool
     * @throws CacheFileException
     */
    public function has(string $cacheKey, string $namespace = ''): bool
    {
        $result = $this->cacheer->cacheStore->has($cacheKey, $namespace);
        $this->cacheer->syncState();

        return $result;
    }

    /**
     * Processes cached data for retrieval.
     * 
     * @param mixed $cachedData
     * @return mixed|CacheDataFormatter
     */
    public function getCachedDatum(mixed $cachedData): mixed
    {
        $this->cacheer->syncState();

        if ($this->cacheer->isSuccess() && ($this->cacheer->isCompressionEnabled() || $this->cacheer->getEncryptionKey() !== null)) {
            foreach ($cachedData as &$data) {
                $data = CacheerHelper::recoverFromStorage($data, $this->cacheer->isCompressionEnabled(), $this->cacheer->getEncryptionKey());
            }
        }

        return $this->cacheer->isFormatted() ? new CacheDataFormatter($cachedData) : $cachedData;
    }
}
