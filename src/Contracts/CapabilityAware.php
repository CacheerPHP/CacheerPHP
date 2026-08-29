<?php

declare(strict_types=1);

namespace Silviooosilva\CacheerPhp\Contracts;

/**
 * Implemented by stores whose capability set is not what `instanceof` says.
 *
 * A decorator cannot conditionally implement an interface in PHP, so wrappers
 * such as TieredStore, ResilientStore, and InstrumentedStore declare every
 * capability and delegate. Without this contract `$store instanceof AtomicStore`
 * would be true for a wrapper around a store that cannot increment, and the
 * kernel would pick a code path that throws at call time.
 *
 * Ask {@see \Silviooosilva\CacheerPhp\Kernel\Capabilities} rather than using
 * `instanceof` directly; it honors this contract and falls back to `instanceof`
 * for plain stores.
 */
interface CapabilityAware
{
    /**
     * Whether this store really honors the given capability interface.
     *
     * @param class-string $capability
     * @return bool
     */
    public function supports(string $capability): bool;
}
