<?php

namespace Silviooosilva\CacheerPhp\Service;

use Silviooosilva\CacheerPhp\Cacheer;
use Silviooosilva\CacheerPhp\Enums\CacheTimeConstants;
use Silviooosilva\CacheerPhp\Helpers\CacheerHelper;

/**
* Class CacheMutator
* @author Sílvio Silva <https://github.com/silviooosilva>
* @package Silviooosilva\CacheerPhp
*/
class CacheMutator
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
    * CacheMutator constructor.
    *
    * @param Cacheer $cacheer
    */
    public function __construct(Cacheer $cacheer)
    {
        $this->cacheer = $cacheer;
    }

    /**
    * Adds a cache item if it does not already exist.
    *
    * @param string $cacheKey
    * @param mixed $cacheData
    * @param string $namespace
    * @param int|string $ttl
    * @return bool
    */
    public function add(string $cacheKey, mixed $cacheData, string $namespace = '', int|string $ttl = 3600): bool
    {
        if (!empty($this->cacheer->getCache($cacheKey, $namespace))) {
            return true;
        }

        $this->putCache($cacheKey, $cacheData, $namespace, $ttl);
        $this->cacheer->setInternalState($this->cacheer->getMessage(), $this->cacheer->isSuccess());

        return false;
    }

    /**
    * Appends data to an existing cache item.
    *
    * @param string $cacheKey
    * @param mixed $cacheData
    * @param string $namespace
    * @return bool
    */
    public function appendCache(string $cacheKey, mixed $cacheData, string $namespace = ''): bool
    {
        $this->cacheer->cacheStore->appendCache($cacheKey, $cacheData, $namespace);
        $this->cacheer->syncState();

        return $this->cacheer->isSuccess();
    }

    /**
    * Clears a specific cache item.
    *
    * @param string $cacheKey
    * @param string $namespace
    * @return bool
    */
    public function clearCache(string $cacheKey, string $namespace = ''): bool
    {
        $this->cacheer->cacheStore->clearCache($cacheKey, $namespace);
        $this->cacheer->syncState();

        return $this->cacheer->isSuccess();
    }

    /**
    * Decrements a numeric cache item by a specified amount.
    *
    * @param string $cacheKey
    * @param int $amount
    * @param string $namespace
    * @return bool
    */
    public function decrement(string $cacheKey, int $amount = 1, string $namespace = ''): bool
    {
        return $this->increment($cacheKey, ($amount * -1), $namespace);
    }

    /**
    * Stores a cache item forever.
     *
     * @param string $cacheKey
     * @param mixed $cacheData
     * @return bool
     */
    public function forever(string $cacheKey, mixed $cacheData): bool
    {
        $this->putCache($cacheKey, $cacheData, ttl: $this->foreverTTL);
        $this->cacheer->setInternalState($this->cacheer->getMessage(), $this->cacheer->isSuccess());

        return $this->cacheer->isSuccess();
    }

    /**
    * Flushes the entire cache.
    *
    * @return bool
    */
    public function flushCache(): bool
    {
        $this->cacheer->cacheStore->flushCache();
        $this->cacheer->syncState();

        return $this->cacheer->isSuccess();
    }

    /**
    * Increments a numeric cache item by a specified amount.
     *
     * @param string $cacheKey
     * @param int $amount
     * @param string $namespace
     * @return bool
     */
    public function increment(string $cacheKey, int $amount = 1, string $namespace = ''): bool
    {
        $cacheData = $this->cacheer->getCache($cacheKey, $namespace);

        if (!empty($cacheData) && is_numeric($cacheData)) {
            $this->putCache($cacheKey, (int)($cacheData + $amount), $namespace);
            $this->cacheer->setInternalState($this->cacheer->getMessage(), $this->cacheer->isSuccess());
            return true;
        }

        return false;
    }

    /**
     * Puts a cache item into the cache store.
     *
     * @param string $cacheKey
     * @param mixed $cacheData
     * @param string $namespace
     * @param int|string $ttl
     * @return bool
     */
    public function putCache(string $cacheKey, mixed $cacheData, string $namespace = '', int|string $ttl = 3600): bool
    {
        $data = CacheerHelper::prepareForStorage($cacheData, $this->cacheer->isCompressionEnabled(), $this->cacheer->getEncryptionKey());
        $this->cacheer->cacheStore->putCache($cacheKey, $data, $namespace, $ttl);
        $this->cacheer->syncState();

        return $this->cacheer->isSuccess();
    }

    /**
    * Puts multiple cache items in a batch.
    *
    * @param array $items
    * @param string $namespace
    * @param int $batchSize
    * @return bool
    */
    public function putMany(array $items, string $namespace = '', int $batchSize = 100): bool
    {
        $this->cacheer->cacheStore->putMany($items, $namespace, $batchSize);
        $this->cacheer->syncState();

        return $this->cacheer->isSuccess();
    }

    /**
    * Renews the cache item with a new TTL.
    *
    * @param string $cacheKey
    * @param int|string $ttl
    * @param string $namespace
    * @return bool
    */
    public function renewCache(string $cacheKey, int|string $ttl = 3600, string $namespace = ''): bool
    {
        $this->cacheer->cacheStore->renewCache($cacheKey, $ttl, $namespace);
        $this->cacheer->syncState();

        return $this->cacheer->isSuccess();
    }

    /**
     * Associates keys to a tag in the current driver.
     *
     * @param string $tag
     * @param string ...$keys
     * @return bool
     */
    public function tag(string $tag, string ...$keys): bool
    {
        $this->cacheer->cacheStore->tag($tag, ...$keys);
        $this->cacheer->syncState();
        return $this->cacheer->isSuccess();
    }

    /**
     * Flushes all keys associated with a tag in the current driver.
     *
     * @param string $tag
     * @return bool
     */
    public function flushTag(string $tag): bool
    {
        $this->cacheer->cacheStore->flushTag($tag);
        $this->cacheer->syncState();
        return $this->cacheer->isSuccess();
    }
}
