<?php

namespace Silviooosilva\CacheerPhp\Service;

use Closure;
use DateInterval;
use Silviooosilva\CacheerPhp\Cacheer;
use Silviooosilva\CacheerPhp\Enums\CacheTimeConstants;
use Silviooosilva\CacheerPhp\Exceptions\CacheFileException;
use Silviooosilva\CacheerPhp\Helpers\CacheerHelper;
use Silviooosilva\CacheerPhp\Interface\LockProviderInterface;
use Silviooosilva\CacheerPhp\Support\CacheLock;
use Silviooosilva\CacheerPhp\Utils\CacheDataFormatter;

/**
 * Class CacheRetriever
 *
 * Handles all read-side operations (get, getMany, getAll, has, remember …)
 * by delegating to the active cache store and syncing status back to Cacheer.
 *
 * @author Sílvio Silva <https://github.com/silviooosilva>
 * @package Silviooosilva\CacheerPhp
 */
class CacheRetriever
{
    /**
     * Lifetime (seconds) of the single-flight lock used by remember()/flexible().
     */
    private const SINGLE_FLIGHT_LOCK_TTL = 30;

    /**
     * Max time (seconds) a waiter blocks for the single-flight lock before
     * falling back to an unguarded compute.
     */
    private const SINGLE_FLIGHT_WAIT = 10;

    /**
     * @var Cacheer
     */
    private Cacheer $cacheer;

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
        $cacheData = $this->cacheer->getCacheStore()->getCache($cacheKey, $namespace, $ttl);
        $this->cacheer->syncState();

        if ($this->cacheer->isSuccess() && ($this->cacheer->isCompressionEnabled() || $this->cacheer->getEncryptionKey() !== null)) {
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
        $cachedData = $this->cacheer->getCacheStore()->getMany($cacheKeys, $namespace, $ttl);
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
        $cachedData = $this->cacheer->getCacheStore()->getAll($namespace);
        return $this->getCachedDatum($cachedData);
    }

    /**
     * Retrieves a cache item, deletes it, and returns its data (atomic pop).
     *
     * Uses isSuccess() — not empty() — so falsy values (0, '', false) are
     * correctly treated as valid cached data rather than cache misses.
     *
     * @param string $cacheKey
     * @param string $namespace
     * @return mixed|null
     * @throws CacheFileException
     */
    public function getAndForget(string $cacheKey, string $namespace = ''): mixed
    {
        $cachedData = $this->getCache($cacheKey, $namespace);

        if ($this->cacheer->isSuccess()) {
            $this->cacheer->setInternalState('Cache retrieved and deleted successfully!', true);
            $this->cacheer->clearCache($cacheKey, $namespace);
            return $cachedData;
        }

        return null;
    }

    /**
     * Retrieves a cache item, or executes a callback to compute and store it.
     *
     * On a miss the recompute is guarded by a per-key single-flight lock, so a
     * burst of concurrent misses runs the callback exactly once (cache-stampede
     * protection) on any lockable driver. Waiters re-check the cache after the
     * lock and return the freshly-stored value. If the driver can't lock, or the
     * lock isn't obtained in time, it falls back to an unguarded compute — never
     * worse than the historical behaviour.
     *
     * Uses isSuccess() — not empty() — so falsy values (0, '', false, []) stored
     * in the cache are returned as-is without re-invoking the callback.
     *
     * @param string $cacheKey
     * @param int|string|\DateInterval|null $ttl
     * @param Closure $callback
     * @param string $namespace
     * @return mixed
     * @throws CacheFileException
     */
    public function remember(string $cacheKey, int|string|DateInterval|null $ttl, Closure $callback, string $namespace = ''): mixed
    {
        $readTtl = is_int($ttl) ? $ttl : 3600;

        $cachedData = $this->getCache($cacheKey, $namespace, $readTtl);
        if ($this->cacheer->isSuccess()) {
            return $cachedData;
        }

        $store = $this->cacheer->getCacheStore();
        if ($store instanceof LockProviderInterface) {
            $lock = new CacheLock($store, 'cacheer:remember:' . $namespace . ':' . $cacheKey, self::SINGLE_FLIGHT_LOCK_TTL);

            if ($lock->block(self::SINGLE_FLIGHT_WAIT)) {
                try {
                    // Double-check: a concurrent worker may have populated it while we waited.
                    $cachedData = $this->getCache($cacheKey, $namespace, $readTtl);
                    if ($this->cacheer->isSuccess()) {
                        return $cachedData;
                    }
                    return $this->computeAndStore($cacheKey, $namespace, $ttl, $callback);
                } finally {
                    $lock->release();
                }
            }
        }

        return $this->computeAndStore($cacheKey, $namespace, $ttl, $callback);
    }

    /**
     * Retrieves a cache item indefinitely, or computes and stores it if absent.
     *
     * @param string $cacheKey
     * @param Closure $callback
     * @param string $namespace
     * @return mixed
     * @throws CacheFileException
     */
    public function rememberForever(string $cacheKey, Closure $callback, string $namespace = ''): mixed
    {
        return $this->remember($cacheKey, CacheTimeConstants::CACHE_FOREVER_TTL->value, $callback, $namespace);
    }

    /**
     * Stale-while-revalidate get-or-compute.
     *
     * Stores the value in an envelope with two horizons: it is served directly
     * while *fresh* (< $fresh seconds old); served immediately but refreshed in
     * the background while *stale* ($fresh..$stale seconds); and recomputed once
     * it is older than $stale. The stale refresh and the cold recompute are both
     * single-flight, so concurrent requests never stampede the callback.
     *
     * @param string  $cacheKey
     * @param int     $fresh     Seconds the value is served without refreshing.
     * @param int     $stale     Seconds the value may still be served (must be > $fresh).
     * @param Closure $callback
     * @param string  $namespace
     * @return mixed
     * @throws CacheFileException
     */
    public function flexible(string $cacheKey, int $fresh, int $stale, Closure $callback, string $namespace = ''): mixed
    {
        $envelope = $this->readRaw($cacheKey, $namespace, $stale);

        if ($this->cacheer->isSuccess() && $this->isSwrEnvelope($envelope)) {
            $now = time();

            if ($now < (int) $envelope['fresh_until']) {
                return $this->formatValue($envelope['value']);
            }

            if ($now < (int) $envelope['stale_until']) {
                // Stale but usable: one request refreshes, everyone else serves stale.
                $store = $this->cacheer->getCacheStore();
                if ($store instanceof LockProviderInterface) {
                    $lock = new CacheLock($store, $this->flexibleLockName($namespace, $cacheKey), self::SINGLE_FLIGHT_LOCK_TTL);
                    if ($lock->acquire()) {
                        try {
                            return $this->formatValue($this->storeFlexible($cacheKey, $namespace, $fresh, $stale, $callback));
                        } finally {
                            $lock->release();
                        }
                    }
                }

                return $this->formatValue($envelope['value']);
            }
        }

        // Cold (or fully expired): compute under a blocking single-flight lock.
        $store = $this->cacheer->getCacheStore();
        if ($store instanceof LockProviderInterface) {
            $lock = new CacheLock($store, $this->flexibleLockName($namespace, $cacheKey), self::SINGLE_FLIGHT_LOCK_TTL);
            if ($lock->block(self::SINGLE_FLIGHT_WAIT)) {
                try {
                    $envelope = $this->readRaw($cacheKey, $namespace, $stale);
                    if ($this->cacheer->isSuccess() && $this->isSwrEnvelope($envelope) && time() < (int) $envelope['fresh_until']) {
                        return $this->formatValue($envelope['value']);
                    }
                    return $this->formatValue($this->storeFlexible($cacheKey, $namespace, $fresh, $stale, $callback));
                } finally {
                    $lock->release();
                }
            }
        }

        return $this->formatValue($this->storeFlexible($cacheKey, $namespace, $fresh, $stale, $callback));
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
        $result = $this->cacheer->getCacheStore()->has($cacheKey, $namespace);
        $this->cacheer->syncState();

        return $result;
    }

    /**
     * Inverse of has().
     *
     * @param string $cacheKey
     * @param string $namespace
     * @return bool
     * @throws CacheFileException
     */
    public function missing(string $cacheKey, string $namespace = ''): bool
    {
        return !$this->has($cacheKey, $namespace);
    }

    /**
     * Alias of getAndForget().
     *
     * @param string $cacheKey
     * @param string $namespace
     * @return mixed
     * @throws CacheFileException
     */
    public function pull(string $cacheKey, string $namespace = ''): mixed
    {
        return $this->getAndForget($cacheKey, $namespace);
    }

    /**
     * Processes cached data for retrieval, applying decompression/decryption
     * and optional formatting.
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

    /**
     * Run the callback and store the result. Shared miss path for remember().
     *
     * @param string $cacheKey
     * @param string $namespace
     * @param int|string|DateInterval|null $ttl
     * @param Closure $callback
     * @return mixed
     */
    private function computeAndStore(string $cacheKey, string $namespace, int|string|DateInterval|null $ttl, Closure $callback): mixed
    {
        $value = $callback();
        $this->cacheer->putCache($cacheKey, $value, $namespace, $ttl);
        return $value;
    }

    /**
     * Compute and store a stale-while-revalidate envelope. Returns the value.
     *
     * @param string $cacheKey
     * @param string $namespace
     * @param int $fresh
     * @param int $stale
     * @param Closure $callback
     * @return mixed
     */
    private function storeFlexible(string $cacheKey, string $namespace, int $fresh, int $stale, Closure $callback): mixed
    {
        $value = $callback();
        $now = time();
        $envelope = [
            '__swr'       => true,
            'value'       => $value,
            'fresh_until' => $now + $fresh,
            'stale_until' => $now + $stale,
        ];

        // The cache TTL matches the stale horizon: once it expires the next read
        // is a true miss and the value is recomputed.
        $this->cacheer->putCache($cacheKey, $envelope, $namespace, $stale);

        return $value;
    }

    /**
     * Read a value applying decompression/decryption but NOT the output
     * formatter, so callers can inspect the raw stored payload (e.g. SWR
     * envelopes). Check isSuccess() afterwards for hit/miss.
     *
     * @param string $cacheKey
     * @param string $namespace
     * @param int|string $ttl
     * @return mixed
     * @throws CacheFileException
     */
    private function readRaw(string $cacheKey, string $namespace = '', int|string $ttl = 3600): mixed
    {
        $cacheData = $this->cacheer->getCacheStore()->getCache($cacheKey, $namespace, $ttl);
        $this->cacheer->syncState();

        if ($this->cacheer->isSuccess() && ($this->cacheer->isCompressionEnabled() || $this->cacheer->getEncryptionKey() !== null)) {
            $cacheData = CacheerHelper::recoverFromStorage($cacheData, $this->cacheer->isCompressionEnabled(), $this->cacheer->getEncryptionKey());
        }

        return $cacheData;
    }

    /**
     * Apply the output formatter to a value when it is enabled.
     *
     * @param mixed $value
     * @return mixed
     */
    private function formatValue(mixed $value): mixed
    {
        return $this->cacheer->isFormatted() ? new CacheDataFormatter($value) : $value;
    }

    /**
     * Whether a raw value is a stale-while-revalidate envelope.
     *
     * @param mixed $value
     * @return bool
     */
    private function isSwrEnvelope(mixed $value): bool
    {
        return is_array($value)
            && ($value['__swr'] ?? false) === true
            && array_key_exists('value', $value)
            && array_key_exists('fresh_until', $value)
            && array_key_exists('stale_until', $value);
    }

    /**
     * Single-flight lock name for a flexible() key.
     *
     * @param string $namespace
     * @param string $cacheKey
     * @return string
     */
    private function flexibleLockName(string $namespace, string $cacheKey): string
    {
        return 'cacheer:flexible:' . $namespace . ':' . $cacheKey;
    }
}
