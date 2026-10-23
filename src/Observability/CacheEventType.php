<?php

declare(strict_types=1);

namespace Silviooosilva\CacheerPhp\Observability;

/**
 * The kinds of cache event the observability layer can emit.
 */
enum CacheEventType: string
{
    case Hit = 'cache.hit';

    case Miss = 'cache.miss';

    case Write = 'cache.write';

    case Delete = 'cache.delete';

    case Clear = 'cache.clear';

    case Prune = 'cache.prune';

    case Failure = 'cache.failure';

    case Promotion = 'cache.promotion';

    case StaleServed = 'cache.stale_served';

    case Refresh = 'cache.refresh';

    case LockContended = 'cache.lock_contended';

    /**
     * A loader ran for a remember()/flexible() miss or refresh; carries its duration.
     */
    case Computed = 'cache.computed';

    /**
     * A resilient store's primary became unavailable and its circuit opened.
     */
    case CircuitOpened = 'cache.circuit_opened';

    /**
     * A resilient store's primary is serving again.
     */
    case CircuitClosed = 'cache.circuit_closed';

    /**
     * An operation was answered or applied by the fallback alone.
     */
    case FallbackServed = 'cache.fallback_served';

    /**
     * Writes made during an outage were invalidated on the recovered primary.
     */
    case Reconciled = 'cache.reconciled';
}
