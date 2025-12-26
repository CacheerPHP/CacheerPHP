<?php

namespace Silviooosilva\CacheerPhp\CacheStore\Support;

use Silviooosilva\CacheerPhp\Helpers\CacheRedisHelper;

/**
 * Handles chunked writes to Redis for large batches.
 */
final class RedisBatchWriter
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
     * @param callable $putItem
     * @param array $items
     * @param string $namespace
     * 
     * @return void
     */
    public function write(array $items, string $namespace, callable $putItem): void
    {
        $processedCount = 0;
        $itemCount = count($items);

        while ($processedCount < $itemCount) {
            $batchItems = array_slice($items, $processedCount, $this->batchSize);
            foreach ($batchItems as $item) {
                $this->processItem($item, $namespace, $putItem);
            }
            $processedCount += count($batchItems);
        }
    }

    /**
     * @param array $item
     * @param string $namespace
     * @param callable $putItem
     * 
     * @return void
     */
    private function processItem(array $item, string $namespace, callable $putItem): void
    {
        CacheRedisHelper::validateCacheItem($item);
        $cacheKey = $item['cacheKey'];
        $cacheData = $item['cacheData'];
        $mergedData = CacheRedisHelper::mergeCacheData($cacheData);

        $putItem($cacheKey, $mergedData, $namespace);
    }
}
