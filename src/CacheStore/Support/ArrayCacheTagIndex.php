<?php

namespace Silviooosilva\CacheerPhp\CacheStore\Support;

/**
 * Manages tag membership for array-backed cache items.
 */
final class ArrayCacheTagIndex
{
    /**
     * @var array<string, array<string,bool>>
     */
    private array $tags = [];

    /**
     * @param ArrayCacheKeyspace $keyspace
     * @param OperationStatus $status
     */
    public function __construct(private ArrayCacheKeyspace $keyspace, private OperationStatus $status)
    {
    }

    /**
     * @param string $tag
     * @param string ...$keys
     * @return bool
     */
    public function tag(string $tag, string ...$keys): bool
    {
        if (!isset($this->tags[$tag])) {
            $this->tags[$tag] = [];
        }
        foreach ($keys as $key) {
            $arrayStoreKey = (str_contains($key, ':')) ? $key : $this->keyspace->build($key, '');
            $this->tags[$tag][$arrayStoreKey] = true;
        }
        $this->status->record("Tagged successfully", true);
        return true;
    }

    /**
     * @param string $tag
     * @param callable $clearCache Receives ($key, $namespace)
     * @return void
     */
    public function flush(string $tag, callable $clearCache): void
    {
        $keys = array_keys($this->tags[$tag] ?? []);
        foreach ($keys as $arrayStoreKey) {
            [$namespace, $key] = $this->keyspace->split($arrayStoreKey);
            $clearCache($key, $namespace);
        }
        unset($this->tags[$tag]);
        $this->status->record("Tag flushed successfully", true);
    }

    /**
     * @return void
     */
    public function reset(): void
    {
        $this->tags = [];
    }
}
