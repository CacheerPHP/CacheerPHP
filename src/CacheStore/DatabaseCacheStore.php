<?php

namespace Silviooosilva\CacheerPhp\CacheStore;

use Silviooosilva\CacheerPhp\Interface\CacheerInterface;
use Silviooosilva\CacheerPhp\Helpers\CacheDatabaseHelper;
use Silviooosilva\CacheerPhp\Utils\CacheLogger;
use Silviooosilva\CacheerPhp\Repositories\CacheDatabaseRepository;
use Silviooosilva\CacheerPhp\CacheStore\CacheManager\GenericFlusher;
use Silviooosilva\CacheerPhp\Helpers\CacheFileHelper;
use Silviooosilva\CacheerPhp\Helpers\FlushHelper;
use Silviooosilva\CacheerPhp\Enums\CacheStoreType;
use Silviooosilva\CacheerPhp\Core\Connect;
use Silviooosilva\CacheerPhp\Core\MigrationManager;

/**
 * Class DatabaseCacheStore
 * @author Sílvio Silva <https://github.com/silviooosilva>
 * @package Silviooosilva\CacheerPhp
 */
class DatabaseCacheStore implements CacheerInterface
{
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
     * @var CacheDatabaseRepository
     */
    private CacheDatabaseRepository $cacheRepository;

    /** @var int|null */
    private ?int $defaultTTL = null;

    /** @var GenericFlusher|null */
    private ?GenericFlusher $flusher = null;

    /**
     * DatabaseCacheStore constructor.
     *
     * @param string $logPath
     * @param array $options
     */
    public function __construct(string $logPath, array $options = [])
    {
        $this->logger = new CacheLogger($logPath);
        $tableOption = $options['table'] ?? 'cacheer_table';
        $table = is_string($tableOption) && $tableOption !== '' ? $tableOption : 'cacheer_table';
        $this->cacheRepository = new CacheDatabaseRepository($table);

        // Ensure the custom table exists by running a targeted migration
        $pdo = Connect::getInstance();
        MigrationManager::migrate($pdo, $table);

        if (!empty($options['expirationTime'])) {
            $this->defaultTTL = (int) CacheFileHelper::convertExpirationToSeconds((string) $options['expirationTime']);
        }

        $lastFlushFile = FlushHelper::pathFor(CacheStoreType::DATABASE, $table);
        $this->flusher = new GenericFlusher($lastFlushFile, function () {
            $this->flushCache();
        });
        $this->flusher->handleAutoFlush($options);
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
        $currentCacheData = $this->getCache($cacheKey, $namespace);
        $mergedCacheData = CacheDatabaseHelper::arrayIdentifier($currentCacheData, $cacheData);

        if ($this->updateCache($cacheKey, $mergedCacheData, $namespace)) {
            $this->logger->debug("{$this->getMessage()} from database driver.");
            return true;
        }

        $this->logger->error("{$this->getMessage()} from database driver.");
        return false;
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
        $data = $this->cacheRepository->clear($cacheKey, $namespace);
        if($data) {
            $this->setMessage("Cache deleted successfully!", true);
        } else {
            $this->setMessage("Cache does not exists!", false);
        }

        $this->logger->debug("{$this->getMessage()} from database driver.");
    }

    /**
     * Flushes all cache items.
     * 
     * @return void
     */
    public function flushCache(): void
    {
        if($this->cacheRepository->flush()){
            $this->setMessage("Flush finished successfully", true);
        } else {
            $this->setMessage("Something went wrong. Please, try again.", false);
        }

        $this->logger->info("{$this->getMessage()} from database driver.");

    }

    /**
     * Associates one or more keys to a tag using a reserved namespace.
     *
     * @param string $tag
     * @param string ...$keys
     * @return bool
     */
    public function tag(string $tag, string ...$keys): bool
    {
        $indexKey = "tag:" . $tag;
        $namespace = '__tags__';
        $existing = $this->cacheRepository->retrieve($indexKey, $namespace) ?? [];
        if (!is_array($existing)) {
            $existing = [];
        }
        foreach ($keys as $key) {
            // Store either raw key or "namespace:key"
            $existing[$key] = true;
        }
        $ok = $this->cacheRepository->store($indexKey, $existing, $namespace, 31536000);
        $this->setMessage($ok ? "Tagged successfully" : "Failed to tag keys", $ok);
        $this->logger->debug("{$this->getMessage()} from database driver.");
        return $ok;
    }

    /**
     * Flush all keys associated with a tag.
     *
     * @param string $tag
     * @return void
     */
    public function flushTag(string $tag): void
    {
        $indexKey = "tag:" . $tag;
        $namespace = '__tags__';
        $existing = $this->cacheRepository->retrieve($indexKey, $namespace) ?? [];
        if (is_array($existing)) {
            foreach (array_keys($existing) as $key) {
                if (str_contains($key, ':')) {
                    [$np, $k] = explode(':', $key, 2);
                    $this->clearCache($k, $np);
                } else {
                    $this->clearCache($key, '');
                }
            }
        }
        $this->cacheRepository->clear($indexKey, $namespace);
        $this->setMessage("Tag flushed successfully", true);
        $this->logger->debug("{$this->getMessage()} from database driver.");
    }

    /**
     * Gets a single cache item.
     * 
     * @param string $cacheKey
     * @param string $namespace
     * @param string|int $ttl
     * @return mixed
     */
    public function getCache(string $cacheKey, string $namespace = '', string|int $ttl = 3600): mixed
    {
        $cacheData = $this->retrieveCache($cacheKey, $namespace);
        if ($cacheData) {
            $this->setMessage("Cache retrieved successfully", true);
            $this->logger->debug("{$this->getMessage()} from database driver.");
            return $cacheData;
        }
        $this->setMessage("CacheData not found, does not exists or expired", false);
        $this->logger->info("{$this->getMessage()} from database driver.");
        return null;
    }

    /**
     * Gets all items in a specific namespace.
     * 
     * @param string $namespace
     * @return array
     */
    public function getAll(string $namespace = ''): array
    {
        $cacheData = $this->cacheRepository->getAll($namespace);
        if ($cacheData) {
            $this->setMessage("Cache retrieved successfully", true);
            $this->logger->debug("{$this->getMessage()} from database driver.");
            return $cacheData;
        }
        $this->setMessage("No cache data found for the provided namespace", false);
        $this->logger->info("{$this->getMessage()} from database driver.");
        return [];
    }

    /**
     * Retrieves multiple cache items by their keys.
     * 
     * @param array  $cacheKeys
     * @param string $namespace
     * @param string|int $ttl
     * @return array
     */
    public function getMany(array $cacheKeys, string $namespace = '', string|int $ttl = 3600): array
    {
        $cacheData = [];
        foreach ($cacheKeys as $cacheKey) {
            $data = $this->getCache($cacheKey, $namespace, $ttl);
            if ($data) {
                $cacheData[$cacheKey] = $data;
            }
        }
        if (!empty($cacheData)) {
            $this->setMessage("Cache retrieved successfully", true);
            $this->logger->debug("{$this->getMessage()} from database driver.");
            return $cacheData;
        }
        $this->setMessage("No cache data found for the provided keys", false);
        $this->logger->info("{$this->getMessage()} from database driver.");
        return [];
    }

    /**
     * Checks if a cache item exists.
     * 
     * @return string
     */
    public function getMessage(): string
    {
        return $this->message;
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
        $cacheData = $this->getCache($cacheKey, $namespace);

        if ($cacheData) {
            $this->setMessage("Cache key: {$cacheKey} exists and it's available from database driver.", true);
            $this->logger->debug("{$this->getMessage()}");
            return true;
        }

        $this->setMessage("Cache key: {$cacheKey} does not exist or it's expired from database driver.", false);
        $this->logger->debug("{$this->getMessage()}");

        return false;
    }

    /**
     * Checks if the last operation was successful.
     * 
     * @return boolean
     */
    public function isSuccess(): bool
    {
        return $this->success;
    }

    /**
     * Store multiple items in the cache.
     * 
     * @param array   $items
     * @param string  $namespace
     * @param integer $batchSize
     * @return void
     */
    public function putMany(array $items, string $namespace = '', int $batchSize = 100): void
    {
        $processedCount = 0;
        $itemCount = count($items);
        while ($processedCount < $itemCount) {
            $batchItems = array_slice($items, $processedCount, $batchSize);
            $this->processBatchItems($batchItems, $namespace);
            $processedCount += count($batchItems);
        }
    }

    /**
     * Stores an item in the cache with a specific TTL.
     * 
     * @param string $cacheKey
     * @param mixed  $cacheData
     * @param string $namespace
     * @param string|int $ttl
     * @return bool
     */
    public function putCache(string $cacheKey, mixed $cacheData, string $namespace = '', string|int $ttl = 3600): bool
    {
        $ttlToUse = $ttl;
        if ($this->defaultTTL !== null && ($ttl === null || (int)$ttl === 3600)) {
            $ttlToUse = $this->defaultTTL;
        }
        if (is_string($ttlToUse)) {
            $ttlToUse = (int) CacheFileHelper::convertExpirationToSeconds($ttlToUse);
        }

        if($this->storeCache($cacheKey, $cacheData, $namespace, $ttlToUse)){
            $this->logger->debug("{$this->getMessage()} from database driver.");
            return true;
        }
        $this->logger->error("{$this->getMessage()} from database driver.");
        return false;
    }

    /**
     * Renews the cache for a specific key with a new TTL.
     * 
     * @param string $cacheKey
     * @param string|int $ttl
     * @param string $namespace
     * @return void
     */
    public function renewCache(string $cacheKey, int | string $ttl, string $namespace = ''): void
    {
        $cacheData = $this->getCache($cacheKey, $namespace);
        if ($cacheData) {
            $this->renew($cacheKey, $ttl, $namespace);
            $this->setMessage("Cache with key {$cacheKey} renewed successfully", true);
            $this->logger->debug("{$this->getMessage()} from database driver.");
        }
    }

    /**
     * Processes a batch of cache items.
     * 
     * @param array  $batchItems
     * @param string $namespace
     * @return void
     */
    private function processBatchItems(array $batchItems, string $namespace): void
    {
        foreach($batchItems as $item) {
            CacheDatabaseHelper::validateCacheItem($item);
            $cacheKey = $item['cacheKey'];
            $cacheData = $item['cacheData'];
            $mergedData = CacheDatabaseHelper::mergeCacheData($cacheData);
            $this->putCache($cacheKey, $mergedData, $namespace);
        }
    }

    /**
     * Renews the expiration time of a cache item.
     * 
     * @param string $cacheKey
     * @param string|int $ttl
     * @param string $namespace
     * @return bool
     */
    private function renew(string $cacheKey, string|int $ttl = 3600, string $namespace = ''): bool
    {
        $cacheData = $this->getCache($cacheKey, $namespace);
        if ($cacheData) {
            $renewedCache = $this->cacheRepository->renew($cacheKey, $ttl, $namespace);
            if ($renewedCache) {
                $this->setMessage("Cache with key {$cacheKey} renewed successfully", true);
                $this->logger->debug("{$this->getMessage()} from database driver.");
                return true;
            }
            return false;
        }
        return false;
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
     * Retrieves a cache item by its key.
     * @param string $cacheKey
     * @param string $namespace
     * @return mixed
     */
    private function retrieveCache(string $cacheKey, string $namespace = ''): mixed
    {
        return $this->cacheRepository->retrieve($cacheKey, $namespace);
    }

    /**
     * Stores a cache item.
     *
     * @param string $cacheKey
     * @param mixed $cacheData
     * @param string $namespace
     * @param string|int $ttl
     * @return bool
     */
    private function storeCache(string $cacheKey, mixed $cacheData, string $namespace = '', string|int $ttl = 3600): bool
    {
        $data = $this->cacheRepository->store($cacheKey, $cacheData, $namespace, $ttl);
        if($data) {
            $this->setMessage("Cache Stored Successfully", true);
            return true;
        }
        $this->setMessage("Already exists a cache with this key...", false);
        return false;
    }

    /**
     * Updates an existing cache item.
     * 
     * @param string $cacheKey
     * @param mixed  $cacheData
     * @param string $namespace
     * @return bool
     */
    private function updateCache(string $cacheKey, mixed $cacheData, string $namespace = ''): bool
    {
        $data = $this->cacheRepository->update($cacheKey, $cacheData, $namespace);
        if($data) {
            $this->setMessage("Cache updated successfully.", true);
            return true;
        }
        $this->setMessage("Cache does not exist or update failed!", false);
        return false;
    }
}
