<?php

declare(strict_types=1);

namespace Silviooosilva\CacheerPhp\Support;

use Silviooosilva\CacheerPhp\Contracts\DeferredExecutor;

/**
 * The default executor: runs deferred work immediately, inline with the caller.
 *
 * With this executor a stale-while-revalidate refresh happens during the same
 * request, so it must not be advertised as a background refresh.
 */
final class SyncDeferredExecutor implements DeferredExecutor
{
    /**
     * @param callable $task
     */
    public function defer(callable $task): void
    {
        $task();
    }
}
