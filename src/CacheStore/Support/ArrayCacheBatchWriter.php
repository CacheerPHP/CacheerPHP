<?php

namespace Silviooosilva\CacheerPhp\CacheStore\Support;

/**
 * Handles chunked writes to the array store.
 */
final class ArrayCacheBatchWriter extends AbstractBatchWriter
{
    /**
     * @param array $item
     * @param string $namespace
     * @param callable $putItem Receives ($cacheKey, $cacheData, $namespace)
     *
     * @return void
     */
    protected function processItem(array $item, string $namespace, callable $putItem): void
    {
        if (!isset($item['cacheKey'], $item['cacheData'])) {
            return;
        }
        $putItem($item['cacheKey'], $item['cacheData'], $namespace);
    }
}
