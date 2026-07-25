<?php

declare(strict_types=1);

namespace Silviooosilva\CacheerPhp\Contracts;

/**
 * Runs work that does not have to finish before the caller continues, such as a
 * stale-while-revalidate refresh.
 *
 * The reference implementation runs the task synchronously; a runtime that can
 * flush the HTTP response first (FPM, a framework "terminate" event) may run it
 * afterwards. Refresh is only ever described as "background" when a genuinely
 * deferred executor is in use.
 */
interface DeferredExecutor
{
    /**
     * @param callable(): void $task
     */
    public function defer(callable $task): void;
}
