<?php

declare(strict_types=1);

namespace Silviooosilva\CacheerPhp\Stores\Support;

use Silviooosilva\CacheerPhp\Contracts\Lock;
use Silviooosilva\CacheerPhp\Support\CircuitBreaker;
use Throwable;

/**
 * A lock on the primary store that fails closed: while the primary is
 * unreachable it reports "not acquired" immediately instead of throwing or
 * waiting, and it never falls back to a lock on another store — two workers on
 * either side of a failover would otherwise both hold "the same" lock.
 *
 * @internal
 */
final class FailClosedLock implements Lock
{
    /**
     * @param ?Lock $inner the primary's lock, or null when it is unavailable
     * @param CircuitBreaker $breaker
     */
    public function __construct(
        private readonly ?Lock $inner,
        private readonly CircuitBreaker $breaker,
    ) {
    }

    public function acquire(): bool
    {
        return $this->attempt(fn (Lock $lock): bool => $lock->acquire());
    }

    public function block(float $seconds): bool
    {
        return $this->attempt(fn (Lock $lock): bool => $lock->block($seconds));
    }

    public function release(): bool
    {
        return $this->attempt(fn (Lock $lock): bool => $lock->release());
    }

    /**
     * @param callable(Lock): bool $operation
     * @return bool
     */
    private function attempt(callable $operation): bool
    {
        if ($this->inner === null) {
            return false;
        }

        try {
            return $operation($this->inner);
        } catch (Throwable $error) {
            if (!BackendFailure::isOutage($error)) {
                throw $error;
            }

            $this->breaker->recordFailure();

            return false;
        }
    }
}
