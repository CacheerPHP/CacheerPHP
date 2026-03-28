<?php

namespace Silviooosilva\CacheerPhp\Service;

use DateInterval;
use Silviooosilva\CacheerPhp\Cacheer;
use Silviooosilva\CacheerPhp\Enums\CacheTimeConstants;
use Silviooosilva\CacheerPhp\Helpers\CacheerHelper;

/**
 * Class CacheMutator
 *
 * Handles all write-side operations (put, clear, flush, renew, increment …)
 * by delegating to the active cache store and syncing status back to Cacheer.
 *
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
     * CacheMutator constructor.
     *
     * @param Cacheer $cacheer
     */
    public function __construct(Cacheer $cacheer)
    {
        $this->cacheer = $cacheer;
    }

    /**
     * Adds a cache item only if the key does not already exist.
     *
     * Returns true  when the item was freshly stored.
     * Returns false when the key already existed (nothing written).
     *
     * @param string $cacheKey
     * @param mixed $cacheData
     * @param string $namespace
     * @param int|string|\DateInterval|null $ttl
     * @return bool
     */
    public function add(string $cacheKey, mixed $cacheData, string $namespace = '', int|string|DateInterval|null $ttl = 3600): bool
    {
        if ($this->cacheer->has($cacheKey, $namespace)) {
            return false;
        }

        $this->putCache($cacheKey, $cacheData, $namespace, $ttl);
        $this->cacheer->setInternalState($this->cacheer->getMessage(), $this->cacheer->isSuccess());

        return $this->cacheer->isSuccess();
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
        $this->cacheer->getCacheStore()->appendCache($cacheKey, $cacheData, $namespace);
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
        $this->cacheer->getCacheStore()->clearCache($cacheKey, $namespace);
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
     * Stores a cache item with no expiration (uses PHP_INT_MAX as TTL).
     *
     * @param string $cacheKey
     * @param mixed $cacheData
     * @return bool
     */
    public function forever(string $cacheKey, mixed $cacheData): bool
    {
        $this->putCache($cacheKey, $cacheData, ttl: CacheTimeConstants::CACHE_FOREVER_TTL->value);
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
        $this->cacheer->getCacheStore()->flushCache();
        $this->cacheer->syncState();

        return $this->cacheer->isSuccess();
    }

    /**
     * Increments a numeric cache item by a specified amount.
     *
     * Unlike the previous implementation, this correctly handles the case where
     * the stored value is 0 — !empty(0) would treat 0 as a miss, which is wrong.
     * We use isSuccess() to distinguish a real hit from a miss.
     *
     * @param string $cacheKey
     * @param int $amount
     * @param string $namespace
     * @return bool
     */
    public function increment(string $cacheKey, int $amount = 1, string $namespace = ''): bool
    {
        $cacheData = $this->cacheer->getCache($cacheKey, $namespace);

        if ($this->cacheer->isSuccess() && is_numeric($cacheData)) {
            $this->putCache($cacheKey, (int) ($cacheData + $amount), $namespace);
            $this->cacheer->setInternalState($this->cacheer->getMessage(), $this->cacheer->isSuccess());
            return true;
        }

        return false;
    }

    /**
     * Puts a cache item into the cache store.
     *
     * The $ttl parameter now accepts int, string, \DateInterval, or null:
     *  - int          → seconds
     *  - string       → human-readable (e.g. "1 hour")
     *  - \DateInterval → converted to seconds
     *  - null         → PHP_INT_MAX (forever)
     *
     * @param string $cacheKey
     * @param mixed $cacheData
     * @param string $namespace
     * @param int|string|\DateInterval|null $ttl
     * @return bool
     */
    public function putCache(string $cacheKey, mixed $cacheData, string $namespace = '', int|string|DateInterval|null $ttl = 3600): bool
    {
        $ttlSeconds = $this->normalizeTtl($ttl);
        $data = CacheerHelper::prepareForStorage($cacheData, $this->cacheer->isCompressionEnabled(), $this->cacheer->getEncryptionKey());
        $this->cacheer->getCacheStore()->putCache($cacheKey, $data, $namespace, $ttlSeconds);
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
        $this->cacheer->getCacheStore()->putMany($items, $namespace, $batchSize);
        $this->cacheer->syncState();

        return $this->cacheer->isSuccess();
    }

    /**
     * Renews the cache item with a new TTL.
     *
     * @param string $cacheKey
     * @param int|string|\DateInterval|null $ttl
     * @param string $namespace
     * @return bool
     */
    public function renewCache(string $cacheKey, int|string|DateInterval|null $ttl = 3600, string $namespace = ''): bool
    {
        $ttlSeconds = $this->normalizeTtl($ttl);
        $this->cacheer->getCacheStore()->renewCache($cacheKey, $ttlSeconds, $namespace);
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
        $this->cacheer->getCacheStore()->tag($tag, ...$keys);
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
        $this->cacheer->getCacheStore()->flushTag($tag);
        $this->cacheer->syncState();
        return $this->cacheer->isSuccess();
    }

    /**
     * Normalises a TTL value to an integer number of seconds.
     *
     * Accepted input types:
     *  - null          → PHP_INT_MAX (store forever)
     *  - int           → used as-is
     *  - string        → parsed by CacheerHelper::convertExpirationToSeconds()
     *  - \DateInterval → converted via date arithmetic
     *
     * @param int|string|\DateInterval|null $ttl
     * @return int
     */
    private function normalizeTtl(int|string|DateInterval|null $ttl): int
    {
        if ($ttl === null) {
            return CacheTimeConstants::CACHE_FOREVER_TTL->value;
        }

        if ($ttl instanceof DateInterval) {
            $now = new \DateTimeImmutable();
            $future = $now->add($ttl);
            return max(0, $future->getTimestamp() - $now->getTimestamp());
        }

        if (is_string($ttl)) {
            return (int) CacheerHelper::convertExpirationToSeconds($ttl);
        }

        return $ttl;
    }
}
