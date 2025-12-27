<?php

namespace Silviooosilva\CacheerPhp\CacheStore;

use Silviooosilva\CacheerPhp\Interface\CacheerInterface;
use Silviooosilva\CacheerPhp\Utils\CacheLogger;
use Silviooosilva\CacheerPhp\CacheStore\Support\ArrayCacheBatchWriter;
use Silviooosilva\CacheerPhp\CacheStore\Support\ArrayCacheCodec;
use Silviooosilva\CacheerPhp\CacheStore\Support\ArrayCacheKeyspace;
use Silviooosilva\CacheerPhp\CacheStore\Support\ArrayCacheTagIndex;
use Silviooosilva\CacheerPhp\CacheStore\Support\OperationStatus;

/**
 * Class ArrayCacheStore
 * @author Sílvio Silva <https://github.com/silviooosilva>
 * @package Silviooosilva\CacheerPhp
 */
class ArrayCacheStore implements CacheerInterface
{

    /**
     * @param array $arrayStore
     */
    private array $arrayStore = [];

    /**
     * @var OperationStatus
     */
    private OperationStatus $status;

    /**
     * @var ArrayCacheKeyspace
     */
    private ArrayCacheKeyspace $keyspace;

    /**
     * @var ArrayCacheCodec
     */
    private ArrayCacheCodec $codec;

    /**
     * @var ArrayCacheTagIndex
     */
    private ArrayCacheTagIndex $tagIndex;

  /**
   * ArrayCacheStore constructor.
   * 
   * @param string $logPath
   */
    public function __construct(string $logPath)
    {
        $logger = new CacheLogger($logPath);
        $this->status = new OperationStatus($logger, 'array');
        $this->keyspace = new ArrayCacheKeyspace();
        $this->codec = new ArrayCacheCodec();
        $this->tagIndex = new ArrayCacheTagIndex($this->keyspace, $this->status);
    }

  /**
   * Appends data to an existing cache item.
   * 
   * @param string $cacheKey
   * @param mixed  $cacheData
   * @param string $namespace
   * @return bool
   */
    public function appendCache(string $cacheKey, mixed $cacheData, string $namespace = ''): bool
    {
        $arrayStoreKey = $this->keyspace->build($cacheKey, $namespace);
        $entry = $this->arrayStore[$arrayStoreKey] ?? null;

        if ($entry === null || $this->keyspace->isExpired($entry)) {
            $this->status->record("cacheData can't be appended, because doesn't exist or expired", false);
            return false;
        }

        $this->arrayStore[$arrayStoreKey]['cacheData'] = $this->codec->encode($cacheData);
        $this->status->record("Cache appended successfully", true);
        return true;
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
        $arrayStoreKey = $this->keyspace->build($cacheKey, $namespace);
        unset($this->arrayStore[$arrayStoreKey]);
        $this->status->record("Cache cleared successfully", true);
    }

  /**
   * Decrements a cache item by a specified amount.
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
   * Flushes all cache items.
   * 
   * @return void
   */
    public function flushCache(): void
    {
        $this->arrayStore = [];
        $this->tagIndex->reset();
        $this->status->record("Cache flushed successfully", true);
    }

    /**
     * Stores a cache item permanently.
     *
     * @param string $cacheKey
     * @param mixed $cacheData
     * @return void
     */
    public function forever(string $cacheKey, mixed $cacheData): void
    {
        $this->putCache($cacheKey, $cacheData, ttl: 31536000 * 1000);
    }

  /**
   * Retrieves a single cache item.
   * 
   * @param string $cacheKey
   * @param string $namespace
   * @param int|string $ttl
   * @return mixed
   */
    public function getCache(string $cacheKey, string $namespace = '', string|int $ttl = 3600): mixed
    {
        $arrayStoreKey = $this->keyspace->build($cacheKey, $namespace);
        $cacheData = $this->arrayStore[$arrayStoreKey] ?? null;

        if ($cacheData === null) {
            $this->status->record("cacheData not found, does not exists or expired", false);
            return false;
        }

        if ($this->keyspace->isExpired($cacheData)) {
            $this->clearCache($cacheKey, $namespace);
            $this->status->record("cacheKey: {$cacheKey} has expired.", false);
            return false;
        }

        $this->status->record("Cache retrieved successfully", true);
        return $this->codec->decode($cacheData['cacheData']);
    }

  /**
   * Gets all items in a specific namespace.
   * 
   * @param string $namespace
   * @return array
   */
    public function getAll(string $namespace = ''): array
    {
        $results = [];
        foreach ($this->arrayStore as $key => $data) {
            if (str_starts_with($key, $namespace . ':') || $namespace === '') {
                $results[$key] = $this->codec->decode($data['cacheData']);
            }
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
            $results[$cacheKey] = $this->getCache($cacheKey, $namespace, $ttl);
        }
        return $results;
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
        $arrayStoreKey = $this->keyspace->build($cacheKey, $namespace);
        $entry = $this->arrayStore[$arrayStoreKey] ?? null;
        $exists = $entry !== null && !$this->keyspace->isExpired($entry);

        $this->status->record(
            $exists ? "Cache key: {$cacheKey} exists and it's available!" : "Cache key: {$cacheKey} does not exist or it's expired!",
            $exists
        );

        return $exists;
    }

  /**
   * Increments a cache item by a specified amount.
   * 
   * @param string $cacheKey
   * @param int $amount
   * @param string $namespace
   * @return bool
   */
    public function increment(string $cacheKey, int $amount = 1, string $namespace = ''): bool
    {
        $cacheData = $this->getCache($cacheKey, $namespace);

        if (!empty($cacheData) && is_numeric($cacheData)) {
            $this->putCache($cacheKey, (int) ($cacheData + $amount), $namespace);
            return true;
        }

        return false;
    }

  /**
   * Checks if the operation was successful.
   * 
   * @return boolean
   */
    public function isSuccess(): bool
    {
        return $this->status->isSuccess();
    }

  /**
   * Gets the last message.
   * 
   * @return string
   */
    public function getMessage(): string
    {
        return $this->status->getMessage();
    }

  /**
   * Stores an item in the cache with a specific TTL.
   * 
   * @param string $cacheKey
   * @param mixed $cacheData
   * @param string $namespace
   * @param int|string $ttl
   * @return bool
   */
    public function putCache(string $cacheKey, mixed $cacheData, string $namespace = '', int|string $ttl = 3600): bool
    {
        $arrayStoreKey = $this->keyspace->build($cacheKey, $namespace);

        $this->arrayStore[$arrayStoreKey] = [
            'cacheData' => $this->codec->encode($cacheData),
            'expirationTime' => time() + $ttl
        ];

        $this->status->record("Cache stored successfully", true);
        return true;
    }

  /**
   * Stores multiple items in the cache in batches.
   * 
   * @param array $items
   * @param string $namespace
   * @param int $batchSize
   * @return void
   */
    public function putMany(array $items, string $namespace = '', int $batchSize = 100): void
    {
        $writer = new ArrayCacheBatchWriter($batchSize);
        $writer->write($items, $namespace, function (string $cacheKey, mixed $cacheData, string $namespace): void {
            $this->putCache($cacheKey, $cacheData, $namespace);
        });

        $this->status->record($this->getMessage(), $this->isSuccess());
    }

  /**
   * Renews the expiration time of a cache item.
   * 
   * @param string $cacheKey
   * @param string|int $ttl
   * @param string $namespace
   * @return void
   */
    public function renewCache(string $cacheKey, int|string $ttl = 3600, string $namespace = ''): void
    {
        $arrayStoreKey = $this->keyspace->build($cacheKey, $namespace);

        if (isset($this->arrayStore[$arrayStoreKey])) {
            $ttlSeconds = is_numeric($ttl) ? (int) $ttl : strtotime($ttl) - time();
            $this->arrayStore[$arrayStoreKey]['expirationTime'] = time() + $ttlSeconds;
            $this->status->record("cacheKey: {$cacheKey} renewed successfully", true);
        }
    }

  /**
   * Associates one or more keys to a tag.
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
   * Flushes all keys associated with a tag.
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
}
