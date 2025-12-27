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
use Silviooosilva\CacheerPhp\CacheStore\Support\DatabaseBatchWriter;
use Silviooosilva\CacheerPhp\CacheStore\Support\DatabaseCacheTagIndex;
use Silviooosilva\CacheerPhp\CacheStore\Support\DatabaseTtlResolver;
use Silviooosilva\CacheerPhp\CacheStore\Support\OperationStatus;

/**
 * Class DatabaseCacheStore
 * @author Sílvio Silva <https://github.com/silviooosilva>
 * @package Silviooosilva\CacheerPhp
 */
class DatabaseCacheStore implements CacheerInterface
{
    /**
     * @var OperationStatus
     */
    private OperationStatus $status;

    /**
     * @var CacheDatabaseRepository
     */
    private CacheDatabaseRepository $cacheRepository;

    /**
     * @var DatabaseCacheTagIndex
     */
    private DatabaseCacheTagIndex $tagIndex;

    /**
     * @var DatabaseBatchWriter
     */
    private DatabaseBatchWriter $batchWriter;

    /**
     * @var DatabaseTtlResolver
     */
    private DatabaseTtlResolver $ttlResolver;

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
        $logger = new CacheLogger($logPath);
        $this->status = new OperationStatus($logger, 'database');
        $tableOption = $options['table'] ?? 'cacheer_table';
        $table = is_string($tableOption) && $tableOption !== '' ? $tableOption : 'cacheer_table';
        $this->cacheRepository = new CacheDatabaseRepository($table);

        // Ensure the custom table exists by running a targeted migration
        $pdo = Connect::getInstance();
        MigrationManager::migrate($pdo, $table);

        $defaultTTL = null;
        if (!empty($options['expirationTime'])) {
            $defaultTTL = (int) CacheFileHelper::convertExpirationToSeconds((string) $options['expirationTime']);
        }

        $this->ttlResolver = new DatabaseTtlResolver($defaultTTL);
        $this->batchWriter = new DatabaseBatchWriter();
        $this->tagIndex = new DatabaseCacheTagIndex($this->cacheRepository, $this->status);

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

        $updated = $this->cacheRepository->update($cacheKey, $mergedCacheData, $namespace);
        if ($updated) {
            $this->status->record("Cache updated successfully.", true);
            return true;
        }

        $this->status->record("Cache does not exist or update failed!", false, 'error');
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
        $deleted = $this->cacheRepository->clear($cacheKey, $namespace);
        $this->status->record($deleted ? "Cache deleted successfully!" : "Cache does not exists!", $deleted);
    }

    /**
     * Flushes all cache items.
     * 
     * @return void
     */
    public function flushCache(): void
    {
        if ($this->cacheRepository->flush()) {
            $this->status->record("Flush finished successfully", true, 'info');
            return;
        }

        $this->status->record("Something went wrong. Please, try again.", false, 'info');
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
     * Gets a single cache item.
     * 
     * @param string $cacheKey
     * @param string $namespace
     * @param string|int $ttl
     * @return mixed
     */
    public function getCache(string $cacheKey, string $namespace = '', string|int $ttl = 3600): mixed
    {
        $cacheData = $this->cacheRepository->retrieve($cacheKey, $namespace);
        if ($cacheData !== null) {
            $this->status->record("Cache retrieved successfully", true);
            return $cacheData;
        }
        $this->status->record("CacheData not found, does not exists or expired", false, 'info');
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
        if (!empty($cacheData)) {
            $this->status->record("Cache retrieved successfully", true);
            return $cacheData;
        }
        $this->status->record("No cache data found for the provided namespace", false, 'info');
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
            if ($data !== null) {
                $cacheData[$cacheKey] = $data;
            }
        }
        if (!empty($cacheData)) {
            $this->status->record("Cache retrieved successfully", true);
            return $cacheData;
        }
        $this->status->record("No cache data found for the provided keys", false, 'info');
        return [];
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
     * Checks if a cache item exists.
     * 
     * @param string $cacheKey
     * @param string $namespace
     * @return bool
     */
    public function has(string $cacheKey, string $namespace = ''): bool
    {
        $cacheData = $this->getCache($cacheKey, $namespace);

        if ($cacheData !== null) {
            $this->status->record("Cache key: {$cacheKey} exists and it's available from database driver.", true);
            return true;
        }

        $this->status->record("Cache key: {$cacheKey} does not exist or it's expired from database driver.", false);

        return false;
    }

    /**
     * Checks if the last operation was successful.
     * 
     * @return boolean
     */
    public function isSuccess(): bool
    {
        return $this->status->isSuccess();
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
        $writer = $batchSize === 100 ? $this->batchWriter : new DatabaseBatchWriter($batchSize);
        $writer->write($items, $namespace, function (string $cacheKey, mixed $cacheData, string $namespace): void {
            $this->putCache($cacheKey, $cacheData, $namespace);
        });
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
        $ttlToUse = $this->ttlResolver->resolve($ttl);
        $stored = $this->cacheRepository->store($cacheKey, $cacheData, $namespace, $ttlToUse);

        if ($stored) {
            $this->status->record("Cache Stored Successfully", true);
            return true;
        }

        $this->status->record("Already exists a cache with this key...", false, 'error');
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
        $ttlToUse = $this->ttlResolver->resolve($ttl);
        $renewed = $this->cacheRepository->renew($cacheKey, $ttlToUse, $namespace);

        if ($renewed) {
            $this->status->record("Cache with key {$cacheKey} renewed successfully", true);
            return;
        }

        $this->status->record("Failed to renew Cache with key {$cacheKey}", false, 'info');
    }
}
