<?php

declare(strict_types=1);

namespace Silviooosilva\CacheerPhp\Exceptions;

/**
 * Raised when a value exceeds the configured size ceiling, either when writing
 * (serialized size) or when reading (decompressed size, guarding against
 * decompression bombs).
 */
final class ValueTooLargeException extends \RuntimeException implements CacheException
{
    /**
     * @param int $size
     * @param int $limit
     * @return ValueTooLargeException
     */
    public static function onWrite(int $size, int $limit): self
    {
        return new self(sprintf(
            'Cache value of %d bytes exceeds the configured limit of %d bytes.',
            $size,
            $limit,
        ));
    }

    /**
     * @param int $limit
     * @return ValueTooLargeException
     */
    public static function onRead(int $limit): self
    {
        return new self(sprintf(
            'Decompressed cache value exceeds the configured limit of %d bytes.',
            $limit,
        ));
    }
}
