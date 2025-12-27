<?php

namespace Silviooosilva\CacheerPhp\CacheStore\Support;

/**
 * Encodes and decodes cached payloads for the array store.
 */
final class ArrayCacheCodec
{
    /**
     * @param mixed $data
     * @return string
     */
    public function encode(mixed $data): string
    {
        return serialize($data);
    }

    /**
     * @param string $data
     * @return mixed
     */
    public function decode(string $data): mixed
    {
        return unserialize($data);
    }
}
