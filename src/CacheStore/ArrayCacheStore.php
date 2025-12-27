<?php

namespace Silviooosilva\CacheerPhp\CacheStore;

use Silviooosilva\CacheerPhp\Utils\CacheLogger;
use Silviooosilva\CacheerPhp\Interface\CacheerInterface;

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
   * @var boolean
   */
  private bool $success = false;

  /**
   * @var string
   */
  private string $message = '';

  /**
   * @var ?CacheLogger
   */
  private ?CacheLogger $logger = null;

  /**
   * @var array<string, array<string,bool>>
   */
  private array $tags = [];

  /**
   * ArrayCacheStore constructor.
   * 
   * @param string $logPath
   */
  public function __construct(string $logPath)
  {
    $this->logger = new CacheLogger($logPath);
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
      $arrayStoreKey = $this->buildArrayKey($cacheKey, $namespace);

      if (!$this->has($cacheKey, $namespace)) {
          $this->setMessage("cacheData can't be appended, because doesn't exist or expired", false);
          $this->logger?->debug("{$this->getMessage()} from array driver.");
          return false;
      }

      $this->arrayStore[$arrayStoreKey]['cacheData'] = serialize($cacheData);
      $this->setMessage("Cache appended successfully", true);
      return true;
  }

  /**
   * Builds a unique key for the array store.
   * 
   * @param string $cacheKey
   * @param string $namespace
   * @return string
   */
  private function buildArrayKey(string $cacheKey, string $namespace = ''): string
  {
    return !empty($namespace) ? ($namespace . ':' . $cacheKey) : $cacheKey;
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
    $arrayStoreKey = $this->buildArrayKey($cacheKey, $namespace);
    unset($this->arrayStore[$arrayStoreKey]);
    $this->setMessage("Cache cleared successfully", true);
    $this->logger->debug("{$this->getMessage()} from array driver.");
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
    unset($this->arrayStore);
    $this->arrayStore = [];
    $this->tags = [];
    $this->setMessage("Cache flushed successfully", true);
    $this->logger->debug("{$this->getMessage()} from array driver.");
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
    $this->setMessage($this->getMessage(), $this->isSuccess());
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
    $arrayStoreKey = $this->buildArrayKey($cacheKey, $namespace);

    if (!$this->has($cacheKey, $namespace)) {
      $this->handleCacheNotFound();
      return false;
    }

    $cacheData = $this->arrayStore[$arrayStoreKey];
    if ($this->isExpired($cacheData)) {
      $this->handleCacheExpired($arrayStoreKey);
      return false;
    }

    $this->setMessage("Cache retrieved successfully", true);
    $this->logger->debug("{$this->getMessage()} from array driver.");
    return $this->serialize($cacheData['cacheData'], false);
  }

  /**
   * Verify if the cache is expired.
   * 
   * @param array $cacheData
   * @return bool
   */
  private function isExpired(array $cacheData): bool
  {
    $expirationTime = $cacheData['expirationTime'] ?? 0;
    $now = time();
    return $expirationTime !== 0 && $now >= $expirationTime;
  }

  /**
   * Handles the case when cache data is not found.
   * 
   * @return void
   */
  private function handleCacheNotFound(): void
  {
    $this->setMessage("cacheData not found, does not exists or expired", false);
    $this->logger->debug("{$this->getMessage()} from array driver.");
  }

  /**
   * Handles the case when cache data has expired.
   * 
   * @param string $arrayStoreKey
   * @return void
   */
  private function handleCacheExpired(string $arrayStoreKey): void
  {
    $parts = explode(':', $arrayStoreKey, 2);
    if (count($parts) === 2) {
      list($np, $key) = $parts;
    } else {
      $np = '';
      $key = $arrayStoreKey;
    }
    $this->clearCache($key, $np);
    $this->setMessage("cacheKey: {$key} has expired.", false);
    $this->logger->debug("{$this->getMessage()} from array driver.");
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
      if (str_starts_with($key, $namespace . ':') || empty($namespace)) {
        $results[$key] = $this->serialize($data['cacheData'], false);
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
    $arrayStoreKey = $this->buildArrayKey($cacheKey, $namespace);
    $exists = isset($this->arrayStore[$arrayStoreKey]) && time() < $this->arrayStore[$arrayStoreKey]['expirationTime'];

    $this->setMessage(
      $exists ? "Cache key: {$cacheKey} exists and it's available!" : "Cache key: {$cacheKey} does not exist or it's expired!",
      $exists
    );
    $this->logger->debug("{$this->getMessage()} from array driver.");

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

    if(!empty($cacheData) && is_numeric($cacheData)) {
      $this->putCache($cacheKey, (int)($cacheData + $amount), $namespace);
      $this->setMessage($this->getMessage(), $this->isSuccess());
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
    return $this->success;
  }

  /**
   * Gets the last message.
   * 
   * @return string
   */
  public function getMessage(): string
  {
    return $this->message;
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
    $arrayStoreKey = $this->buildArrayKey($cacheKey, $namespace);

    $this->arrayStore[$arrayStoreKey] = [
      'cacheData' => serialize($cacheData),
      'expirationTime' => time() + $ttl
    ];

    $this->setMessage("Cache stored successfully", true);
    $this->logger->debug("{$this->getMessage()} from Array driver.");
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
    $chunks = array_chunk($items, $batchSize, true);

    foreach ($chunks as $chunk) {
      foreach ($chunk as $key => $data) {
          $this->putCache($data['cacheKey'], $data['cacheData'], $namespace);
        }
      }
    $this->setMessage("{$this->getMessage()}", $this->isSuccess());
    $this->logger->debug("{$this->getMessage()} from Array driver.");
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
    $arrayStoreKey = $this->buildArrayKey($cacheKey, $namespace);

    if (isset($this->arrayStore[$arrayStoreKey])) {
        $ttlSeconds = is_numeric($ttl) ? (int) $ttl : strtotime($ttl) - time();
        $this->arrayStore[$arrayStoreKey]['expirationTime'] = time() + $ttlSeconds;
        $this->setMessage("cacheKey: {$cacheKey} renewed successfully", true);
        $this->logger->debug("{$this->getMessage()} from array driver.");
      }
  }

  /**
   * Sets a message and its success status.
   * 
   * @param string  $message
   * @param boolean $success
   * @return void
   */
  private function setMessage(string $message, bool $success): void
  {
    $this->message = $message;
    $this->success = $success;
  }

  /**
   * Serializes or unserializes data based on the flag.
   * 
   * @param mixed $data
   * @param bool $serialize
   * @return mixed
   */
  private function serialize(mixed $data, bool $serialize = true): mixed
  {
    return $serialize ? serialize($data) : unserialize($data);
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
    if (!isset($this->tags[$tag])) {
      $this->tags[$tag] = [];
    }
    foreach ($keys as $key) {
      // Accept either raw key or "namespace:key"
      $arrayStoreKey = (str_contains($key, ':')) ? $key : $this->buildArrayKey($key, '');
      $this->tags[$tag][$arrayStoreKey] = true;
    }
    $this->setMessage("Tagged successfully", true);
    $this->logger?->debug("{$this->getMessage()} from array driver.");
    return true;
  }

  /**
   * Flushes all keys associated with a tag.
   *
   * @param string $tag
   * @return void
   */
  public function flushTag(string $tag): void
  {
    $keys = array_keys($this->tags[$tag] ?? []);
    foreach ($keys as $arrayStoreKey) {
      // Recover original key/namespace combination
      $parts = explode(':', $arrayStoreKey, 2);
      if (count($parts) === 2) {
        [$np, $key] = $parts;
      } else {
        $np = '';
        $key = $arrayStoreKey;
      }
      $this->clearCache($key, $np);
    }
    unset($this->tags[$tag]);
    $this->setMessage("Tag flushed successfully", true);
    $this->logger?->debug("{$this->getMessage()} from array driver.");
  }
}
