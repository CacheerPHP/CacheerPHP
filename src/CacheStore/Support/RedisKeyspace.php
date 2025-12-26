<?php

namespace Silviooosilva\CacheerPhp\CacheStore\Support;

use Silviooosilva\CacheerPhp\Helpers\CacheFileHelper;

/**
 * Handles namespacing and TTL resolution for Redis cache keys.
 */
final class RedisKeyspace
{   
    /** @var string */
    private string $namespace;

    /** @var int|null */
    private ?int $defaultTTL;

    /**
     * @param string $namespace
     * @param int|null $defaultTTL
     * 
     * @return void
     */
    public function __construct(string $namespace = '', ?int $defaultTTL = null)
    {
        $this->namespace = $namespace;
        $this->defaultTTL = $defaultTTL;
    }

    /**
     * @param string $key
     * @param string $namespace
     * 
     * @return string
     */
    public function build(string $key, string $namespace = ''): string
    {
        return $this->namespace . ($namespace ? $namespace . ':' : '') . $key;
    }

    /**
     * @param string $tag
     * 
     * @return string
     */
    public function tagKey(string $tag): string
    {
        return 'tag:' . $tag;
    }

    /**
     * @param string|int|null $ttl
     * 
     * @return int|null
     */
    public function resolveTTL(string|int|null $ttl): ?int
    {
        $ttlToUse = $ttl;

        if ($this->defaultTTL !== null && ($ttl === null || (int) $ttl === 3600)) {
            $ttlToUse = $this->defaultTTL;
        }

        if (is_string($ttlToUse)) {
            $ttlToUse = (int) CacheFileHelper::convertExpirationToSeconds($ttlToUse);
        }

        return $ttlToUse === null ? null : (int) $ttlToUse;
    }
}
