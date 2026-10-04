<?php

declare(strict_types=1);

namespace Silviooosilva\CacheerPhp\Observability;

use Silviooosilva\CacheerPhp\Contracts\EventDispatcher;
use Throwable;

/**
 * Dispatches a telemetry event without letting the dispatcher affect the cache.
 *
 * EventBus already isolates its own listeners, but a cache can be given any
 * EventDispatcher (a PSR-14 bridge, a custom one). Events are emitted after an
 * operation has completed, so a failing dispatcher must neither turn a
 * successful write into an error nor replace the error of a failed one.
 *
 * @internal
 */
final class SafeDispatch
{
    /**
     * @param EventDispatcher $events
     * @param CacheEvent $event
     */
    public static function to(EventDispatcher $events, CacheEvent $event): void
    {
        try {
            $events->dispatch($event);
        } catch (Throwable) {
            // Telemetry is best-effort; the cache operation's outcome stands.
        }
    }
}
