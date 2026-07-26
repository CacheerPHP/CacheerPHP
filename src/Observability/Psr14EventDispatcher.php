<?php

declare(strict_types=1);

namespace Silviooosilva\CacheerPhp\Observability;

use Psr\EventDispatcher\EventDispatcherInterface;
use Silviooosilva\CacheerPhp\Contracts\EventDispatcher;

/**
 * Bridges cache events onto a PSR-14 dispatcher, so applications already using
 * one receive CacheEvent objects through their existing listener wiring.
 */
final class Psr14EventDispatcher implements EventDispatcher
{
    public function __construct(private readonly EventDispatcherInterface $psr)
    {
    }

    public function dispatch(CacheEvent $event): void
    {
        $this->psr->dispatch($event);
    }
}
