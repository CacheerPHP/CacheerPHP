<?php

namespace Silviooosilva\CacheerPhp\CacheStore\Support;

use Silviooosilva\CacheerPhp\Helpers\CacheerHelper;
use Silviooosilva\CacheerPhp\Repositories\CacheDatabaseRepository;

/**
 * Manages tag membership for database-backed cache items.
 */
final class DatabaseCacheTagIndex
{
    /**
     * @var CacheDatabaseRepository
     */
    private CacheDatabaseRepository $repository;

    /**
     * @var OperationStatus
     */
    private OperationStatus $status;

    /**
     * @var string
     */
    private string $namespace;

    /**
     * @param CacheDatabaseRepository $repository
     * @param OperationStatus $status
     * @param string $namespace
     */
    public function __construct(CacheDatabaseRepository $repository, OperationStatus $status, string $namespace = '__tags__')
    {
        $this->repository = $repository;
        $this->status = $status;
        $this->namespace = $namespace;
    }

    /**
     * @param string $tag
     * @param string ...$keys
     * @return bool
     */
    public function tag(string $tag, string ...$keys): bool
    {
        $indexKey = 'tag:' . $tag;
        $existing = $this->repository->retrieve($indexKey, $this->namespace) ?? [];
        if (!is_array($existing)) {
            $existing = [];
        }
        foreach ($keys as $key) {
            $existing[$key] = true;
        }
        $ok = $this->repository->store($indexKey, $existing, $this->namespace, 31536000);
        $this->status->record($ok ? 'Tagged successfully' : 'Failed to tag keys', $ok);
        return $ok;
    }

    /**
     * @param string $tag
     * @param callable $clearCache Receives ($key, $namespace)
     * @return void
     */
    public function flush(string $tag, callable $clearCache): void
    {
        $indexKey = 'tag:' . $tag;
        $existing = $this->repository->retrieve($indexKey, $this->namespace) ?? [];
        if (is_array($existing)) {
            foreach (array_keys($existing) as $key) {
                [$namespace, $cacheKey] = CacheerHelper::splitKey($key);
                $clearCache($cacheKey, $namespace);
            }
        }
        $this->repository->clear($indexKey, $this->namespace);
        $this->status->record('Tag flushed successfully', true);
    }

}
