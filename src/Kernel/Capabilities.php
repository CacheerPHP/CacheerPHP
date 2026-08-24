<?php

declare(strict_types=1);

namespace Silviooosilva\CacheerPhp\Kernel;

use Silviooosilva\CacheerPhp\Contracts\CapabilityAware;
use Silviooosilva\CacheerPhp\Contracts\Store;
use Silviooosilva\CacheerPhp\Exceptions\UnsupportedCapabilityException;

/**
 * The single place that answers "can this store really do X?".
 *
 * Plain stores answer with `instanceof`. Decorators cannot — PHP has no
 * conditional interface implementation, so a wrapper must declare every
 * capability it might forward and can only honor the ones its wrapped store
 * honors. They answer through {@see CapabilityAware} instead.
 *
 * Never use `instanceof` on a store to pick a code path; use this.
 */
final class Capabilities
{
    /**
     * The store typed as the capability, or null when it does not honor it.
     * Prefer this when the next step is a call, so the narrowed type survives.
     *
     * @template T of object
     * @param class-string<T> $capability
     * @return T|null
     */
    public static function as(Store $store, string $capability): ?object
    {
        if (!$store instanceof $capability) {
            return null;
        }

        if ($store instanceof CapabilityAware && !$store->supports($capability)) {
            return null;
        }

        return $store;
    }

    /**
     * @param class-string $capability
     */
    public static function supports(Store $store, string $capability): bool
    {
        return self::as($store, $capability) !== null;
    }

    /**
     * The store typed as the capability, or a clear failure naming both the
     * capability and the operation that needed it.
     *
     * @template T of object
     * @param class-string<T> $capability
     * @return T
     */
    public static function require(Store $store, string $capability, string $operation): object
    {
        return self::as($store, $capability)
            ?? throw UnsupportedCapabilityException::for($capability, $operation);
    }
}
