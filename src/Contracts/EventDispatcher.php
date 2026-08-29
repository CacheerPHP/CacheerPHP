<?php

declare(strict_types=1);

namespace Silviooosilva\CacheerPhp\Contracts;

use Silviooosilva\CacheerPhp\Observability\CacheEvent;

/**
 * Receives cache events. Implementations must never let a listener failure
 * propagate into the cache operation that emitted the event.
 */
interface EventDispatcher
{
    /**
     * @param CacheEvent $event
     */
    public function dispatch(CacheEvent $event): void;
}
