<?php

namespace Silviooosilva\CacheerPhp\CacheStore;

use Exception;
use Silviooosilva\CacheerPhp\CacheStore\CacheManager\GenericFlusher;
use Silviooosilva\CacheerPhp\CacheStore\CacheManager\RedisCacheManager;
use Silviooosilva\CacheerPhp\CacheStore\Support\OperationStatus;
use Silviooosilva\CacheerPhp\CacheStore\Support\RedisBatchWriter;
use Silviooosilva\CacheerPhp\CacheStore\Support\RedisKeyspace;
use Silviooosilva\CacheerPhp\CacheStore\Support\RedisTagIndex;
use Silviooosilva\CacheerPhp\Enums\CacheStoreType;
use Silviooosilva\CacheerPhp\Exceptions\CacheRedisException;
use Silviooosilva\CacheerPhp\Helpers\CacheerHelper;
use Silviooosilva\CacheerPhp\Helpers\CacheRedisHelper;
use Silviooosilva\CacheerPhp\Helpers\FlushHelper;
use Silviooosilva\CacheerPhp\Interface\CacheerInterface;
use Silviooosilva\CacheerPhp\Interface\CacheReadStoreInterface;
use Silviooosilva\CacheerPhp\Interface\CacheWriteStoreInterface;
use Silviooosilva\CacheerPhp\Interface\TaggableCacheStoreInterface;

/**
 * Class RedisCacheStore
 *
 * Redis-backed cache store. Uses the Predis client under the hood.
 *
 * @author Sílvio Silva <https://github.com/silviooosilva>
 * @package Silviooosilva\CacheerPhp
 */
class RedisCacheStore implements CacheerInterface, CacheReadStoreInterface, CacheWriteStoreInterface, TaggableCacheStoreInterface
{
    /**
     * @var mixed
     */
    private $redis;

    /**
     * @var RedisKeyspace
     */
    private RedisKeyspace $keyspace;

    /**
     * @var OperationStatus
     */
    private OperationStatus $status;

    /**
     * @var GenericFlusher|null
     */
    private ?GenericFlusher $flusher = null;

    /**
     * @var RedisTagIndex
     */
    private RedisTagIndex $tagIndex;

    /**
     * RedisCacheStore constructor.
     *
     * @param string $logPath
     * @param array $options
     */
    public function __construct(string $logPath, array $options = [])
    {
        $this->redis = RedisCacheManager::connect();

        $namespace = !empty($options['namespace']) ? (string) $options['namespace'] : '';
        $defaultTTL = null;

        if (!empty($options['expirationTime'])) {
            $defaultTTL = (int) CacheerHelper::convertExpirationToSeconds((string) $options['expirationTime']);
        }

        $this->keyspace = new RedisKeyspace($namespace, $defaultTTL);
        $this->status = OperationStatus::create($logPath, 'redis');
        $this->tagIndex = new RedisTagIndex($this->redis, $this->keyspace, $this->status);

        $lastFlushFile = FlushHelper::pathFor(CacheStoreType::REDIS, $namespace ?: 'default');
        $this->flusher = new GenericFlusher($lastFlushFile, function () {
            $this->flushCache();
        });
        $this->flusher->handleAutoFlush($options);
    }

    /**
     * Appends data to an existing cache item.
     *
     * Merges the new data with whatever is already stored under the key and
     * overwrites the Redis value. Returns true on success, false on failure.
     *
     * @param string $cacheKey
     * @param mixed $cacheData
     * @param string $namespace
     * @return bool
     */
    public function appendCache(string $cacheKey, mixed $cacheData, string $namespace = ''): bool
    {
        $cacheFullKey = $this->buildKey($cacheKey, $namespace);
        $existingData = $this->getCache($cacheKey, $namespace);
        $mergedData = CacheRedisHelper::arrayIdentifier($existingData, $cacheData);
        $serializedData = CacheRedisHelper::serialize($mergedData);

        if ($this->redis->set($cacheFullKey, $serializedData)) {
            $this->status->record('Cache appended successfully', true);
        } else {
            $this->status->record('Something went wrong. Please, try again.', false);
        }

        return $this->isSuccess();
    }

    /**
     * Builds a unique key for the Redis cache.
     *
     * @param string $key
     * @param string $namespace
     * @return string
     */
    private function buildKey(string $key, string $namespace): string
    {
        return $this->keyspace->build($key, $namespace);
    }

    /**
     * Clears a specific cache item.
     *
     * @param string $cacheKey
     * @param string $namespace
     * @return void
     */
    public function clearCache(string $cacheKey, string $namespace = ''): void
    {
        $cacheFullKey = $this->buildKey($cacheKey, $namespace);

        if ($this->redis->del($cacheFullKey) > 0) {
            $this->status->record('Cache cleared successfully', true);
        } else {
            $this->status->record('Something went wrong. Please, try again.', false);
        }
    }

    /**
     * Flushes all cache items in Redis.
     *
     * @return void
     */
    public function flushCache(): void
    {
        if ($this->redis->flushall()) {
            $this->status->record('Cache flushed successfully', true);
        } else {
            $this->status->record('Something went wrong. Please, try again.', false);
        }
    }

    /**
     * Associates one or more keys to a tag using a Redis Set.
     *
     * @param string $tag
     * @param string ...$keys
     * @return bool
     */
    public function tag(string $tag, string ...$keys): bool
    {
        return $this->tagIndex->tag($tag, ...$keys);
    }

    /**
     * Flush all keys associated with a tag.
     *
     * @param string $tag
     * @return void
     */
    public function flushTag(string $tag): void
    {
        $this->tagIndex->flush($tag, function (string $cacheKey, string $namespace): void {
            $this->clearCache($cacheKey, $namespace);
        });
    }

    /**
     * Retrieves a single cache item by its key.
     *
     * @param string $cacheKey
     * @param string $namespace
     * @param string|int $ttl
     * @return mixed
     */
    public function getCache(string $cacheKey, string $namespace = '', string|int $ttl = 3600): mixed
    {
        $fullCacheKey = $this->buildKey($cacheKey, $namespace);
        $cacheData = $this->redis->get($fullCacheKey);

        if ($cacheData) {
            $this->status->record('Cache retrieved successfully', true);
            return CacheRedisHelper::serialize($cacheData, false);
        }

        $this->status->record('CacheData not found, does not exist or has expired', false, 'info');
        return null;
    }

    /**
     * Retrieves all cache items in a specific namespace.
     *
     * @param string $namespace
     * @return array
     */
    public function getAll(string $namespace = ''): array
    {
        $keys = $this->redis->keys($this->buildKey('*', $namespace));
        $results = [];
        $prefix = $this->buildKey('', $namespace);
        $prefixLen = strlen($prefix);

        foreach ($keys as $fullKey) {
            $cacheKey = substr($fullKey, $prefixLen);
            $cacheData = $this->getCache($cacheKey, $namespace);
            if ($cacheData !== null) {
                $results[$cacheKey] = $cacheData;
            }
        }

        if (empty($results)) {
            $this->status->record('No cache data found in the namespace', false);
        } else {
            $this->status->record('Cache data retrieved successfully', true);
        }

        return $results;
    }

    /**
     * Retrieves multiple cache items by their keys.
     *
     * @param array $cacheKeys
     * @param string $namespace
     * @param string|int $ttl
     * @return array
     */
    public function getMany(array $cacheKeys, string $namespace = '', string|int $ttl = 3600): array
    {
        $results = [];
        foreach ($cacheKeys as $cacheKey) {
            $cacheData = $this->getCache($cacheKey, $namespace, $ttl);
            if ($cacheData !== null) {
                $results[$cacheKey] = $cacheData;
            }
        }

        if (empty($results)) {
            $this->status->record('No cache data found for the provided keys', false);
        } else {
            $this->status->record('Cache data retrieved successfully', true);
        }

        return $results;
    }

    /**
     * Gets the message from the last operation.
     *
     * @return string
     */
    public function getMessage(): string
    {
        return $this->status->getMessage();
    }

    /**
     * Gets the serialized dump of a cache item (used by renewCache).
     *
     * @param string $fullKey
     * @return string|null
     */
    private function getDump(string $fullKey): ?string
    {
        return $this->redis->dump($fullKey);
    }

    /**
     * Checks if a cache item exists.
     *
     * @param string $cacheKey
     * @param string $namespace
     * @return bool
     */
    public function has(string $cacheKey, string $namespace = ''): bool
    {
        $cacheFullKey = $this->buildKey($cacheKey, $namespace);

        if ($this->redis->exists($cacheFullKey) > 0) {
            $this->status->record("Cache Key: {$cacheKey} exists!", true);
            return true;
        }

        $this->status->record("Cache Key: {$cacheKey} does not exist!", false);
        return false;
    }

    /**
     * Checks if the last operation was successful.
     *
     * @return bool
     */
    public function isSuccess(): bool
    {
        return $this->status->isSuccess();
    }

    /**
     * Stores a cache item in Redis with optional namespace and TTL.
     *
     * The return type changed from ?Status to bool in v5.0.0 to comply with
     * the CacheerInterface contract.
     *
     * @param string $cacheKey
     * @param mixed $cacheData
     * @param string $namespace
     * @param string|int $ttl
     * @return bool
     */
    public function putCache(string $cacheKey, mixed $cacheData, string $namespace = '', string|int $ttl = 3600): bool
    {
        $cacheFullKey = $this->buildKey($cacheKey, $namespace);
        $serializedData = CacheRedisHelper::serialize($cacheData);
        $ttlToUse = $this->keyspace->resolveTTL($ttl);

        // When TTL is PHP_INT_MAX (forever), Redis SETEX rejects the value;
        // use plain SET (no expiry) instead.
        $useExpiry = $ttlToUse && $ttlToUse < PHP_INT_MAX;
        $result = $useExpiry
            ? $this->redis->setex($cacheFullKey, (int) $ttlToUse, $serializedData)
            : $this->redis->set($cacheFullKey, $serializedData);

        if ($result) {
            $this->status->record('Cache stored successfully', true);
        } else {
            $this->status->record('Failed to store cache', false);
        }

        return $this->isSuccess();
    }

    /**
     * Stores multiple cache items in Redis in batches.
     *
     * @param array $items
     * @param string $namespace
     * @param int $batchSize
     * @return void
     */
    public function putMany(array $items, string $namespace = '', int $batchSize = 100): void
    {
        $writer = new RedisBatchWriter($batchSize);
        $writer->write($items, $namespace, function (string $cacheKey, mixed $cacheData, string $ns): void {
            $this->putCache($cacheKey, $cacheData, $ns);
        });
    }

    /**
     * Renews the cache for a specific key with a new TTL.
     *
     * @param string $cacheKey
     * @param string|int $ttl
     * @param string $namespace
     * @return void
     * @throws CacheRedisException
     */
    public function renewCache(string $cacheKey, string|int $ttl, string $namespace = ''): void
    {
        $cacheFullKey = $this->buildKey($cacheKey, $namespace);
        $dump = $this->getDump($cacheFullKey);

        if (!$dump) {
            $this->status->record("Cache Key: {$cacheKey} not found.", false, 'warning');
            return;
        }

        $this->clearCache($cacheKey, $namespace);

        if ($this->restoreKey($cacheFullKey, $ttl, $dump)) {
            $this->status->record("Cache Key: {$cacheKey} renewed successfully.", true);
        } else {
            $this->status->record("Failed to renew cache key: {$cacheKey}.", false, 'error');
        }
    }

    /**
     * Restores a key in Redis with a given TTL and serialized data.
     *
     * @param string $fullKey
     * @param string|int $ttl
     * @param mixed $dump
     * @return bool
     * @throws CacheRedisException
     */
    private function restoreKey(string $fullKey, string|int $ttl, mixed $dump): bool
    {
        try {
            $this->redis->restore($fullKey, $ttl * 1000, $dump, 'REPLACE');
            return true;
        } catch (Exception $e) {
            throw CacheRedisException::create($e->getMessage());
        }
    }
}
