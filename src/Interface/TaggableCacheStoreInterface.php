<?php

namespace Silviooosilva\CacheerPhp\Interface;

interface TaggableCacheStoreInterface
{
    /**
     * Associates the given cache keys with the specified tag.
     * 
     * @param string $tag
     * @param string ...$keys
     * @return void
     */
    public function tag(string $tag, string ...$keys);

    /**
     * Flushes all cache entries associated with the given tag.
     * 
     * @param string $tag
     * @return void
     */
    public function flushTag(string $tag);
}
