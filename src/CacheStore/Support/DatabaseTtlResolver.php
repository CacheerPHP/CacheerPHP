<?php

namespace Silviooosilva\CacheerPhp\CacheStore\Support;

use Silviooosilva\CacheerPhp\Helpers\CacheerHelper;

/**
 * Resolves TTL values for the database store.
 */
final class DatabaseTtlResolver
{
    /**
     * @var int|null
     */
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
        return (int) CacheerHelper::normalizeTtl($ttl, $this->defaultTTL);
    }
}
