<?php

namespace Silviooosilva\CacheerPhp\CacheStore\Support;

/**
 * Manages tag membership for Redis-backed cache items.
 */
final class RedisTagIndex
{
    /** @var mixed */
    private $redis;

    /** @var RedisKeyspace */
    private RedisKeyspace $keyspace;

    /** @var OperationStatus */
    private OperationStatus $status;

    /**
     * @param mixed $redis Redis client instance
     * @param RedisKeyspace $keyspace Keyspace handler
     * @param OperationStatus $status Operation status tracker
     * @return void
     */
    public function __construct(mixed $redis, RedisKeyspace $keyspace, OperationStatus $status)
    {
        $this->redis = $redis;
        $this->keyspace = $keyspace;
        $this->status = $status;
    }

    /**
     * @param string $tag
     * @param string ...$keys
     * @return bool
     */
    public function tag(string $tag, string ...$keys): bool
    {
        $setKey = $this->keyspace->tagKey($tag);
        $added = 0;
        foreach ($keys as $key) {
            $added += (int) $this->redis->sadd($setKey, [$key]);
        }

        $this->status->record('Tagged successfully', true);
        return $added >= 0;
    }

    /**
     * @param callable $clearCache Receives ($key, $namespace)
     * @param string $tag
     * 
     * @return void
     */
    public function flush(string $tag, callable $clearCache): void
    {
        $setKey = $this->keyspace->tagKey($tag);
        $members = $this->redis->smembers($setKey) ?? [];

        foreach ($members as $key) {
            if (str_contains($key, ':')) {
                [$namespace, $cacheKey] = explode(':', $key, 2);
                $clearCache($cacheKey, $namespace);
            } else {
                $clearCache($key, '');
            }
        }

        $this->redis->del($setKey);
        $this->status->record('Tag flushed successfully', true);
    }
}
