<?php

declare(strict_types=1);

namespace Silviooosilva\CacheerPhp\Support;

use Silviooosilva\CacheerPhp\Contracts\DeferredExecutor;
use Throwable;

/**
 * Queues deferred work and runs it after the response.
 *
 * Tasks are collected and flushed on shutdown (or explicitly via flush()). When
 * the runtime exposes fastcgi_finish_request(), the response is sent to the
 * client before the queue runs, so a stale-while-revalidate refresh genuinely
 * happens off the request's critical path. A failing task never affects the
 * others or the response.
 */
final class AfterResponseDeferredExecutor implements DeferredExecutor
{
    /**
     * @var list<callable(): void>
     */
    private array $tasks = [];

    private bool $registered = false;

    public function defer(callable $task): void
    {
        $this->tasks[] = $task;

        if (!$this->registered) {
            register_shutdown_function(function (): void {
                $this->flush();
            });
            $this->registered = true;
        }
    }

    public function flush(): void
    {
        if ($this->tasks === []) {
            return;
        }

        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }

        while ($this->tasks !== []) {
            $task = array_shift($this->tasks);

            try {
                $task();
            } catch (Throwable) {
                // A deferred refresh failing must not affect other tasks.
            }
        }
    }

    public function pending(): int
    {
        return count($this->tasks);
    }
}
