<?php

declare(strict_types=1);

namespace Silviooosilva\CacheerPhp\Exceptions;

/**
 * Thrown when an operation needs a capability the store does not honor.
 *
 * Names both the capability interface and the operation, so the fix is either a
 * different store or a {@see \Silviooosilva\CacheerPhp\Kernel\Capabilities} check first.
 */
final class UnsupportedCapabilityException extends \RuntimeException implements CacheException
{
    /**
     * @param string $capability
     * @param string $operation
     * @return UnsupportedCapabilityException
     */
    public static function for(string $capability, string $operation): self
    {
        return new self(sprintf(
            'Store %s is required for cache operation "%s".',
            $capability,
            $operation,
        ));
    }
}
