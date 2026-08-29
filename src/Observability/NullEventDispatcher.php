<?php

declare(strict_types=1);

namespace Silviooosilva\CacheerPhp\Observability;

use Silviooosilva\CacheerPhp\Contracts\EventDispatcher;

/**
 * The default dispatcher: discards every event. Used wherever instrumentation
 * is optional, so the uninstrumented path carries no overhead.
 */
final class NullEventDispatcher implements EventDispatcher
{
    /**
     * @param CacheEvent $event
     */
    public function dispatch(CacheEvent $event): void
    {
    }
}
