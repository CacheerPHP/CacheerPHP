<?php

namespace Silviooosilva\CacheerPhp\Interface;

interface TaggableCacheStoreInterface
{
    /**
     * Associates the given cache keys with the specified tag.
     *
     * @param string $tag
     * @param string ...$keys
     * @return bool True if the tag association was stored successfully.
     */
    public function tag(string $tag, string ...$keys): bool;

    /**
     * Flushes all cache entries associated with the given tag.
     *
     * @param string $tag
     * @return void
     */
    public function flushTag(string $tag): void;
}
