<?php

declare(strict_types=1);

namespace Tests\Support;

use Closure;
use RuntimeException;

/**
 * Small process harness for exercising shared cache stores under contention.
 */
final class ConcurrencyHarness
{
    public function isAvailable(): bool
    {
        return function_exists('pcntl_fork') && function_exists('pcntl_waitpid');
    }

    /**
     * @param Closure(int):bool $worker
     * @return list<int> Child exit codes.
     */
    public function run(int $workers, Closure $worker): array
    {
        if (!$this->isAvailable()) {
            throw new RuntimeException('The pcntl extension is required by the concurrency harness.');
        }

        $pids = [];

        for ($index = 0; $index < $workers; $index++) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                throw new RuntimeException('Unable to fork a concurrency worker.');
            }

            if ($pid === 0) {
                try {
                    exit($worker($index) ? 0 : 1);
                } catch (\Throwable) {
                    exit(2);
                }
            }

            $pids[] = $pid;
        }

        $exitCodes = [];
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
            $exitCodes[] = pcntl_wifexited($status) ? pcntl_wexitstatus($status) : 255;
        }

        return $exitCodes;
    }

    public function release(string $barrier): void
    {
        file_put_contents($barrier, 'go', LOCK_EX);
    }

    public function await(string $barrier, int $timeoutMicroseconds = 2_000_000): bool
    {
        $waited = 0;
        while (!is_file($barrier) && $waited < $timeoutMicroseconds) {
            usleep(1_000);
            $waited += 1_000;
        }

        return is_file($barrier);
    }
}
