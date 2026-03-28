<?php

namespace Silviooosilva\CacheerPhp\CacheStore\Support;

use Silviooosilva\CacheerPhp\Exceptions\CacheDatabaseException;
use Silviooosilva\CacheerPhp\Helpers\CacheerHelper;
use Silviooosilva\CacheerPhp\Helpers\CacheDatabaseHelper;

/**
 * Handles chunked writes to the database cache store.
 */
final class DatabaseBatchWriter extends AbstractBatchWriter
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
        CacheerHelper::validateCacheItem($item, fn ($msg) => CacheDatabaseException::create($msg));
        $cacheKey = $item['cacheKey'];
        $cacheData = $item['cacheData'];
        $mergedData = CacheDatabaseHelper::mergeCacheData($cacheData);
        $putItem($cacheKey, $mergedData, $namespace);
    }
}
