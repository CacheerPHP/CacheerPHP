<?php

namespace Silviooosilva\CacheerPhp\CacheStore\Support;

use Silviooosilva\CacheerPhp\Helpers\CacheDatabaseHelper;

/**
 * Handles chunked writes to the database cache store.
 */
final class DatabaseBatchWriter
{
    /** @var int */
    private int $batchSize;

    /**
     * @param int $batchSize
     */
    public function __construct(int $batchSize = 100)
    {
        $this->batchSize = $batchSize;
    }

    /**
     * @param array $items
     * @param string $namespace
     * @param callable $putItem Receives ($cacheKey, $cacheData, $namespace)
     * @return void
     */
    public function write(array $items, string $namespace, callable $putItem): void
    {
        $processedCount = 0;
        $itemCount = count($items);

        while ($processedCount < $itemCount) {
            $batchItems = array_slice($items, $processedCount, $this->batchSize);
            foreach ($batchItems as $item) {
                CacheDatabaseHelper::validateCacheItem($item);
                $cacheKey = $item['cacheKey'];
                $cacheData = $item['cacheData'];
                $mergedData = CacheDatabaseHelper::mergeCacheData($cacheData);
                $putItem($cacheKey, $mergedData, $namespace);
            }
            $processedCount += count($batchItems);
        }
    }
}
