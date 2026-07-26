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
}
