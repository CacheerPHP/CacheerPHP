<?php

namespace Silviooosilva\CacheerPhp\CacheStore\Support;

use Silviooosilva\CacheerPhp\CacheStore\FileCacheStore;
use Silviooosilva\CacheerPhp\Exceptions\CacheFileException;
use Silviooosilva\CacheerPhp\Helpers\CacheFileHelper;

/**
 * Class FileCacheBatchProcessor
 * @author Sílvio Silva <https://github.com/silviooosilva>
 * @package Silviooosilva\CacheerPhp
 */
class FileCacheBatchProcessor
{

    /**
     * FileCacheBatchProcessor constructor.
     *
     * @param FileCacheStore $store
     */
    public function __construct(private FileCacheStore $store)
    {
    }

    /**
     * Processes a batch of cache items and stores them.
     *
     * @param array $batchItems
     * @param string $namespace
     * @return void
     * @throws CacheFileException
     */
    public function process(array $batchItems, string $namespace): void
    {
        foreach ($batchItems as $item) {
            CacheFileHelper::validateCacheItem($item);
            $cacheKey = $item['cacheKey'];
            $cacheData = $item['cacheData'];
            $mergedData = CacheFileHelper::mergeCacheData($cacheData);
            $this->store->putCache($cacheKey, $mergedData, $namespace);
        }
    }

    /**
     * Processes items in batches and stores them.
     *
     * @param array $items
     * @param string $namespace
     * @param int $batchSize
     * @return void
     * @throws CacheFileException
     */
    public function processBatches(array $items, string $namespace, int $batchSize = 100): void
    {
        $processedCount = 0;
        $itemCount = count($items);

        while ($processedCount < $itemCount) {
            $batchItems = array_slice($items, $processedCount, $batchSize);
            $this->process($batchItems, $namespace);
            $processedCount += count($batchItems);
        }
    }
}
