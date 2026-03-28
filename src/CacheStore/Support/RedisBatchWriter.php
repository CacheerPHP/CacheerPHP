<?php

namespace Silviooosilva\CacheerPhp\CacheStore\Support;

use Silviooosilva\CacheerPhp\Exceptions\CacheRedisException;
use Silviooosilva\CacheerPhp\Helpers\CacheerHelper;
use Silviooosilva\CacheerPhp\Helpers\CacheRedisHelper;

/**
 * Handles chunked writes to Redis for large batches.
 */
final class RedisBatchWriter extends AbstractBatchWriter
{
    /**
     * @param array $item
     * @param string $namespace
     * @param callable $putItem
     *
     * @return void
     */
    protected function processItem(array $item, string $namespace, callable $putItem): void
    {
        CacheerHelper::validateCacheItem($item, fn ($msg) => CacheRedisException::create($msg));
        $cacheKey = $item['cacheKey'];
        $cacheData = $item['cacheData'];
        $mergedData = CacheRedisHelper::mergeCacheData($cacheData);

        $putItem($cacheKey, $mergedData, $namespace);
    }
}
