<?php

declare(strict_types=1);

namespace Silviooosilva\CacheerPhp\Observability;

use Silviooosilva\CacheerPhp\Contracts\EventDispatcher;
use Throwable;

/**
 * A simple synchronous event dispatcher.
 *
 * Listeners are called in registration order, each wrapped so a throwing
 * listener can never break the cache operation that emitted the event.
 */
final class EventBus implements EventDispatcher
{
    /**
     * @var list<callable(CacheEvent): void>
     */
    private array $listeners = [];

    /**
     * @param callable(CacheEvent): void $listener
     */
    public function listen(callable $listener): self
    {
        $this->listeners[] = $listener;

        return $this;
    }

    public function dispatch(CacheEvent $event): void
    {
        foreach ($this->listeners as $listener) {
            try {
                $listener($event);
            } catch (Throwable) {
                // A telemetry listener must never break a cache operation.
            }
        }
    }
}
