<?php

namespace Silviooosilva\CacheerPhp\CacheStore\Support;

/**
 * Handles chunked writes to the array store.
 */
final class ArrayCacheBatchWriter
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
                if (!isset($item['cacheKey'], $item['cacheData'])) {
                    continue;
                }
                $putItem($item['cacheKey'], $item['cacheData'], $namespace);
            }
            $processedCount += count($batchItems);
        }
    }
}
