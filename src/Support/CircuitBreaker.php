<?php

declare(strict_types=1);

namespace Silviooosilva\CacheerPhp\Support;

use Silviooosilva\CacheerPhp\Contracts\Clock;

/**
 * A three-state circuit breaker (closed, open, half-open).
 *
 * Closed lets calls through and counts consecutive failures; once the threshold
 * is crossed it trips open and short-circuits calls until the recovery window
 * elapses, at which point it goes half-open and admits a single probe. A
 * successful probe closes it; a failed one re-opens it. All timing is measured
 * against the injected clock, so behavior is deterministic in tests.
 */
final class CircuitBreaker
{
    public const CLOSED = 'closed';

    public const OPEN = 'open';

    public const HALF_OPEN = 'half-open';

    private string $state = self::CLOSED;

    private int $failures = 0;

    private float $openedAt = 0.0;

    public function __construct(
        private readonly Clock $clock,
        private readonly int $failureThreshold = 5,
        private readonly float $recoverySeconds = 30.0,
    ) {
    }

    public function canAttempt(): bool
    {
        if ($this->state === self::OPEN && ($this->clock->nowFloat() - $this->openedAt) >= $this->recoverySeconds) {
            $this->state = self::HALF_OPEN;
        }

        return $this->state !== self::OPEN;
    }

    public function recordSuccess(): void
    {
        $this->state = self::CLOSED;
        $this->failures = 0;
    }

    public function recordFailure(): void
    {
        if ($this->state === self::HALF_OPEN) {
            $this->trip();

            return;
        }

        $this->failures++;
        if ($this->failures >= $this->failureThreshold) {
            $this->trip();
        }
    }

    public function state(): string
    {
        return $this->state;
    }

    public function isHealthy(): bool
    {
        return $this->state === self::CLOSED;
    }

    private function trip(): void
    {
        $this->state = self::OPEN;
        $this->openedAt = $this->clock->nowFloat();
    }
}
