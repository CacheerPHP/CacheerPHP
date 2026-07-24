<?php

declare(strict_types=1);

namespace Silviooosilva\CacheerPhp\Exceptions;

final class UnsupportedCapabilityException extends \RuntimeException implements CacheException
{
    public static function for(string $capability, string $operation): self
    {
        return new self(sprintf(
            'Store %s is required for cache operation "%s".',
            $capability,
            $operation,
        ));
    }
}
