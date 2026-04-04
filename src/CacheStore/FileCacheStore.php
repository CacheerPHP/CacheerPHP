<?php

namespace Silviooosilva\CacheerPhp\CacheStore;

use Silviooosilva\CacheerPhp\CacheStore\CacheManager\FileCacheFlusher;
use Silviooosilva\CacheerPhp\CacheStore\CacheManager\FileCacheManager;
use Silviooosilva\CacheerPhp\CacheStore\Support\FileCacheBatchProcessor;
use Silviooosilva\CacheerPhp\CacheStore\Support\FileCachePathBuilder;
use Silviooosilva\CacheerPhp\CacheStore\Support\FileCacheTagIndex;
use Silviooosilva\CacheerPhp\CacheStore\Support\OperationStatus;
use Silviooosilva\CacheerPhp\Exceptions\CacheFileException;
use Silviooosilva\CacheerPhp\Helpers\CacheerHelper;
use Silviooosilva\CacheerPhp\Helpers\CacheFileHelper;
use Silviooosilva\CacheerPhp\Interface\CacheerInterface;

/**
 * Class FileCacheStore
 * @author Sílvio Silva <https://github.com/silviooosilva>
 * @package Silviooosilva\CacheerPhp
 */
class FileCacheStore implements CacheerInterface
{
    /**
     * @var string
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
     * @var int
     */
    private int $defaultTTL = 3600;

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
     *
     * @param array $options
     * @throws CacheFileException
     */
    public function __construct(array $options = [])
    {
        $this->fileManager = new FileCacheManager();
        $loggerPath = $options['loggerPath'] ?? 'cacheer.log';
        $this->status = OperationStatus::create($loggerPath, 'file');

        $this->validateOptions($options);
        $this->initializeCacheDir($options['cacheDir']);

        $this->pathBuilder = new FileCachePathBuilder($this->fileManager, $this->cacheDir);
        $this->batchProcessor = new FileCacheBatchProcessor($this);
        $this->flusher = new FileCacheFlusher($this->fileManager, $this->cacheDir);
        $this->tagIndex = new FileCacheTagIndex($this->fileManager, $this->cacheDir, $this->status);

        if (isset($options['expirationTime'])) {
            $this->defaultTTL = (int) CacheerHelper::convertExpirationToSeconds((string) $options['expirationTime']);
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
            $this->status->record('Cache updated successfully', true);
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
            $this->status->record('Cache file deleted successfully!', true);
        } else {
            $this->status->record('Cache file does not exist!', false);
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
        $this->status->record('Cache flushed successfully', true);
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
     * Retrieves the status message from the last operation.
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

        if (!$this->fileManager->fileExists($cacheFile)) {
            $this->status->record('cacheFile not found, does not exist or has expired.', false, 'info');
            return null;
        }

        $raw = $this->fileManager->serialize($this->fileManager->readFile($cacheFile), false);

        // v5.0.0 envelope format
        if (is_array($raw) && isset($raw['expires_at'], $raw['data'])) {
            if (time() > $raw['expires_at']) {
                $this->fileManager->removeFile($cacheFile);
                $this->status->record('cacheFile not found, does not exist or has expired.', false, 'info');
                return null;
            }

            $this->status->record('Cache retrieved successfully', true);
            return $raw['data'];
        }

        // Legacy v4.x format: fall back to filemtime-based TTL check
        if (filemtime($cacheFile) <= (time() - $ttlSeconds)) {
            $this->status->record('cacheFile not found, does not exist or has expired.', false, 'info');
            return null;
        }

        $this->status->record('Cache retrieved successfully', true);
        return $raw;
    }

    /**
     * Gets all cache items in a specific namespace.
     *
     * @param string $namespace
     * @return array
     * @throws CacheFileException
     */
    public function getAll(string $namespace = ''): array
    {
        $cacheDir = $this->pathBuilder->namespaceDir($namespace);

        if (!$this->fileManager->directoryExists($cacheDir)) {
            $this->status->record('Cache directory does not exist', false, 'info');
            return [];
        }

        $results = $this->collectCacheEntries($cacheDir);

        if (!empty($results)) {
            $this->status->record('Cache retrieved successfully', true);
            return $results;
        }

        $this->status->record('No cache data found for the provided namespace', false, 'info');
        return [];
    }

    /**
     * Collects cache entries from a directory, filtering by .cache extension and checking for expiration.
     *
     * @param string $cacheDir
     * @return array
     * @throws CacheFileException
     */
    private function collectCacheEntries(string $cacheDir): array
    {
        $results = [];
        foreach ($this->fileManager->getFilesInDirectory($cacheDir) as $file) {
            if (pathinfo($file, PATHINFO_EXTENSION) !== 'cache') {
                continue;
            }
            $data = $this->extractCacheEntry($file);
            if ($data !== null) {
                $results[basename($file, '.cache')] = $data;
            }
        }
        return $results;
    }

    /**
     * Extracts cache data from a file, checking for expiration based on the envelope format.
     *
     * @param string $file
     * @return mixed|null
     * @throws CacheFileException
     */
    private function extractCacheEntry(string $file): mixed
    {
        $raw = $this->fileManager->serialize($this->fileManager->readFile($file), false);

        if (is_array($raw) && isset($raw['expires_at'], $raw['data'])) {
            return time() > $raw['expires_at'] ? null : $raw['data'];
        }

        return $raw;
    }

    /**
     * Retrieves cache data for multiple keys.
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
            $results[$cacheKey] = $this->isSuccess() ? $cacheData : null;
        }

        return $results;
    }

    /**
     * Stores multiple cache items in batches.
     *
     * @param array $items
     * @param string $namespace
     * @param int $batchSize
     * @return void
     */
    public function putMany(array $items, string $namespace = '', int $batchSize = 100): void
    {
        $this->batchProcessor->processBatches($items, $namespace, $batchSize);
    }

    /**
     * Stores an item in the cache with a specific TTL.
     *
     * Since v5.0.0 the data is wrapped in a metadata envelope so the expiry
     * time is stored alongside the payload, enabling true per-item TTL support.
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
        $ttlSeconds = CacheFileHelper::ttl($ttl, $this->defaultTTL);
        $cacheFile = $this->pathBuilder->build($cacheKey, $namespace);

        $envelope = [
            'data'       => $cacheData,
            'expires_at' => time() + $ttlSeconds,
            'ttl'        => $ttlSeconds,
        ];

        $this->fileManager->writeFile($cacheFile, $this->fileManager->serialize($envelope));
        $this->status->record('Cache file created successfully', true);
        return true;
    }

    /**
     * Checks if a cache key exists and is not expired.
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

        $this->status->record("Cache key: {$cacheKey} does not exist or it's expired! from file driver", false);
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
     * @return bool
     */
    public function isSuccess(): bool
    {
        return $this->status->isSuccess();
    }

    /**
     * Validates the options provided to the cache store.
     *
     * The 'cacheDir' key is always required. The previous v4.x implementation
     * also required a 'drive' key set to 'file', which was never populated by
     * the driver layer, making the check effectively dead code.
     *
     * @param array $options
     * @return void
     * @throws CacheFileException
     */
    private function validateOptions(array $options): void
    {
        if (!isset($options['cacheDir'])) {
            $this->status->record("The 'cacheDir' option is required by the file driver.", false);
            throw CacheFileException::create("The 'cacheDir' option is required.");
        }
    }

    /**
     * Initializes the cache directory, creating it if it does not exist.
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
