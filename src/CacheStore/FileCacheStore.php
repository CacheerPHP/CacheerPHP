<?php

namespace Silviooosilva\CacheerPhp\CacheStore;

use Silviooosilva\CacheerPhp\Interface\CacheerInterface;
use Silviooosilva\CacheerPhp\CacheStore\CacheManager\FileCacheManager;
use Silviooosilva\CacheerPhp\CacheStore\CacheManager\FileCacheFlusher;
use Silviooosilva\CacheerPhp\Exceptions\CacheFileException;
use Silviooosilva\CacheerPhp\Helpers\CacheFileHelper;
use Silviooosilva\CacheerPhp\Utils\CacheLogger;
use Silviooosilva\CacheerPhp\CacheStore\Support\FileCachePathBuilder;
use Silviooosilva\CacheerPhp\CacheStore\Support\FileCacheBatchProcessor;
use Silviooosilva\CacheerPhp\CacheStore\Support\FileCacheTagIndex;
use Silviooosilva\CacheerPhp\CacheStore\Support\OperationStatus;

/**
 * Class FileCacheStore
 * @author Sílvio Silva <https://github.com/silviooosilva>
 * @package Silviooosilva\CacheerPhp
 */
class FileCacheStore implements CacheerInterface
{
    /**
     * @param string $cacheDir
     */
    private string $cacheDir;

    /**
     * @var FileCachePathBuilder
     */
    private FileCachePathBuilder $pathBuilder;
    
    /**
     * @var FileCacheBatchProcessor
     */
    private FileCacheBatchProcessor $batchProcessor;
    /**
     * @param integer $defaultTTL
     */
    private int $defaultTTL = 3600; // 1 hour default TTL

    /**
     * @var OperationStatus
     */
    private OperationStatus $status;

    /**
    * @var FileCacheManager
    */
    private FileCacheManager $fileManager;

    /**
    * @var FileCacheFlusher
    */
    private FileCacheFlusher $flusher;

    /**
     * @var FileCacheTagIndex
     */
    private FileCacheTagIndex $tagIndex;


    /**
     * FileCacheStore constructor.
     * @param array $options
     * @throws CacheFileException
     */
    public function __construct(array $options = [])
    {
        $this->fileManager = new FileCacheManager();
        $loggerPath = $options['loggerPath'] ?? 'cacheer.log';
        $this->status = new OperationStatus(new CacheLogger($loggerPath), 'file');

        $this->validateOptions($options);
        $this->initializeCacheDir($options['cacheDir']);
        $this->pathBuilder = new FileCachePathBuilder($this->fileManager, $this->cacheDir);
        $this->batchProcessor = new FileCacheBatchProcessor($this);
        $this->flusher = new FileCacheFlusher($this->fileManager, $this->cacheDir);
        $this->tagIndex = new FileCacheTagIndex($this->fileManager, $this->cacheDir, $this->status);
        if (isset($options['expirationTime'])) {
            $this->defaultTTL = (int) CacheFileHelper::convertExpirationToSeconds((string) $options['expirationTime']);
        }
        $this->flusher->handleAutoFlush($options);
    }

    /**
     * Appends data to an existing cache item.
     *
     * @param string $cacheKey
     * @param mixed $cacheData
     * @param string $namespace
     * @return bool
     * @throws CacheFileException
     */
    public function appendCache(string $cacheKey, mixed $cacheData, string $namespace = ''): bool
    {
        $currentCacheFileData = $this->getCache($cacheKey, $namespace);

        if (!$this->isSuccess()) {
            return false;
        }

        $mergedCacheData = CacheFileHelper::arrayIdentifier($currentCacheFileData, $cacheData);


        $this->putCache($cacheKey, $mergedCacheData, $namespace);
        if ($this->isSuccess()) {
            $this->status->record("Cache updated successfully", true);
            return true;
        }

        return false;
    }

    /**
     * Clears a specific cache item.
     *
     * @param string $cacheKey
     * @param string $namespace
     * @return void
     * @throws CacheFileException
     */
    public function clearCache(string $cacheKey, string $namespace = ''): void
    {
        $cacheFile = $this->pathBuilder->build($cacheKey, $namespace);
        if ($this->fileManager->fileExists($cacheFile)) {
            $this->fileManager->removeFile($cacheFile);
            $this->status->record("Cache file deleted successfully!", true);
        } else {
            $this->status->record("Cache file does not exist!", false);
        }
    }

    /**
     * Flushes all cache items.
     * 
     * @return void
     */
    public function flushCache(): void
    {
        $this->flusher->flushCache();
        $this->status->record("Cache flushed successfully", true);
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
     * Retrieves a message indicating the status of the last operation.
     * 
     * @return string
     */
    public function getMessage(): string
    {
        return $this->status->getMessage();
    }

    /**
     * Retrieves a single cache item.
     *
     * @param string $cacheKey
     * @param string $namespace
     * @param string|int $ttl
     * @return mixed
     * @throws CacheFileException
     */
    public function getCache(string $cacheKey, string $namespace = '', string|int $ttl = 3600): mixed
    {
        $ttlSeconds = CacheFileHelper::ttl($ttl, $this->defaultTTL);
        $cacheFile = $this->pathBuilder->build($cacheKey, $namespace);
        $valid = $this->fileManager->fileExists($cacheFile)
            && filemtime($cacheFile) > (time() - $ttlSeconds);

        if ($valid) {
            $cacheData = $this->fileManager->serialize($this->fileManager->readFile($cacheFile), false);
            $this->status->record("Cache retrieved successfully", true);
            return $cacheData;
        }

        $this->status->record("cacheFile not found, does not exists or expired", false, 'info');
        return null;
    }

    /**
     * @param string $namespace
     * @return array
     * @throws CacheFileException
     */
    public function getAll(string $namespace = ''): array
    {
        $cacheDir = $this->pathBuilder->namespaceDir($namespace);

        if (!$this->fileManager->directoryExists($cacheDir)) {
            $this->status->record("Cache directory does not exist", false, 'info');
            return [];
        }

        $files = $this->fileManager->getFilesInDirectory($cacheDir);
        $results = [];

        foreach ($files as $file) {
            if (pathinfo($file, PATHINFO_EXTENSION) === 'cache') {
                $cacheKey = basename($file, '.cache');
                $cacheData = $this->fileManager->serialize($this->fileManager->readFile($file), false);
                $results[$cacheKey] = $cacheData;
            }
        }

        if (!empty($results)) {
            $this->status->record("Cache retrieved successfully", true);
            return $results;
        }

        $this->status->record("No cache data found for the provided namespace", false, 'info');
        return [];
    }

    /**
     * Gets the cache data for multiple keys.
     *
     * @param array $cacheKeys
     * @param string $namespace
     * @param string|int $ttl
     * @return array
     * @throws CacheFileException
     */
    public function getMany(array $cacheKeys, string $namespace = '', string|int $ttl = 3600): array
    {
        $ttl = CacheFileHelper::ttl($ttl, $this->defaultTTL);
        $results = [];

        foreach ($cacheKeys as $cacheKey) {
            $cacheData = $this->getCache($cacheKey, $namespace, $ttl);
            if ($this->isSuccess()) {
                $results[$cacheKey] = $cacheData;
            } else {
                $results[$cacheKey] = null;
            }
        }

        return $results;
    }

    /**
     * Stores multiple cache items in batches.
     * 
     * @param array   $items
     * @param string  $namespace
     * @param integer $batchSize
     * @return void
     */
    public function putMany(array $items, string $namespace = '', int $batchSize = 100): void
    {
        $this->batchProcessor->processBatches($items, $namespace, $batchSize);
    }

    /**
     * Stores an item in the cache with a specific TTL.
     *
     * @param string $cacheKey
     * @param mixed $cacheData
     * @param string $namespace
     * @param string|int $ttl
     * @return bool
     * @throws CacheFileException
     */
    public function putCache(string $cacheKey, mixed $cacheData, string $namespace = '', string|int $ttl = 3600): bool
    {
        $cacheFile = $this->pathBuilder->build($cacheKey, $namespace);
        $data = $this->fileManager->serialize($cacheData);

        $this->fileManager->writeFile($cacheFile, $data);
        $this->status->record("Cache file created successfully", true);
        return true;
    }

    /**
     * Checks if a cache key exists.
     *
     * @param string $cacheKey
     * @param string $namespace
     * @return bool
     * @throws CacheFileException
     */
    public function has(string $cacheKey, string $namespace = ''): bool
    {
        $this->getCache($cacheKey, $namespace);

        if ($this->isSuccess()) {
            $this->status->record("Cache key: {$cacheKey} exists and it's available! from file driver", true);
            return true;
        }

        $this->status->record("Cache key: {$cacheKey} does not exists or it's expired! from file driver", false);
        return false;
    }

    /**
     * Renews the cache for a specific key.
     *
     * @param string $cacheKey
     * @param string|int $ttl
     * @param string $namespace
     * @return void
     * @throws CacheFileException
     */
    public function renewCache(string $cacheKey, string|int $ttl, string $namespace = ''): void
    {
        $cacheData = $this->getCache($cacheKey, $namespace);
        if ($cacheData !== null) {
            $this->putCache($cacheKey, $cacheData, $namespace, $ttl);
            $this->status->record("Cache with key {$cacheKey} renewed successfully", true);
            return;
        }
        $this->status->record("Failed to renew Cache with key {$cacheKey}", false);
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
     * Validates the options provided to the cache store.
     *
     * @param array $options
     * @return void
     * @throws CacheFileException
     */
    private function validateOptions(array $options): void
    {
        if (!isset($options['cacheDir']) && ($options['drive'] ?? null) === 'file') {
            $this->status->record("The 'cacheDir' option is required from file driver.", false);
            throw CacheFileException::create("The 'cacheDir' option is required.");
        }
    }

    /**
     * Initializes the cache directory.
     *
     * @param string $cacheDir
     * @return void
     * @throws CacheFileException
     */
    private function initializeCacheDir(string $cacheDir): void
    {
        $this->fileManager->createDirectory($cacheDir);
        $this->cacheDir = realpath($cacheDir) ?: $cacheDir;
    }
}
