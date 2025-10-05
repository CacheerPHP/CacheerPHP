<?php

namespace Silviooosilva\CacheerPhp\Helpers;

use Silviooosilva\CacheerPhp\Enums\CacheStoreType;

/**
 * Class FlushHelper
 * 
 * @author Sílvio Silva <https://github.com/silviooosilva>
 * @package Silviooosilva\CacheerPhp
 * 
 * Builds deterministic file paths for last-flush timestamps per store type.
 */
class FlushHelper
{
    /**
     * Returns a path to store a last-flush timestamp.
     * @param CacheStoreType|string $storeType e.g., 'redis' or 'db'
     * @param string $identifier e.g., namespace or table name
     * @return string
     */
    public static function pathFor(CacheStoreType|string $storeType, string $identifier): string
    {
        $store = $storeType instanceof CacheStoreType ? $storeType->value : $storeType;
        $root = EnvHelper::getRootPath();
        $dir = $root . DIRECTORY_SEPARATOR . 'CacheerPHP' . DIRECTORY_SEPARATOR . 'Flush';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $safeId = preg_replace('/[^a-zA-Z0-9_-]+/', '_', $identifier);
        return $dir . DIRECTORY_SEPARATOR . $store . '_' . $safeId . '.time';
    }
}
