<?php

namespace Silviooosilva\CacheerPhp\CacheStore\Support;

use Silviooosilva\CacheerPhp\Helpers\CacheFileHelper;

/**
 * Resolves TTL values for the database store.
 */
final class DatabaseTtlResolver
{
    /** @var int|null */
    private ?int $defaultTTL;

    /**
     * @param int|null $defaultTTL
     */
    public function __construct(?int $defaultTTL)
    {
        $this->defaultTTL = $defaultTTL;
    }

    /**
     * @param string|int|null $ttl
     * @return int
     */
    public function resolve(string|int|null $ttl): int
    {
        $ttlToUse = $ttl;

        if ($this->defaultTTL !== null && ($ttl === null || (int) $ttl === 3600)) {
            $ttlToUse = $this->defaultTTL;
        }

        if (is_string($ttlToUse)) {
            $ttlToUse = (int) CacheFileHelper::convertExpirationToSeconds($ttlToUse);
        }

        return (int) $ttlToUse;
    }
}
