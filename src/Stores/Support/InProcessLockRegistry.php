<?php

declare(strict_types=1);

namespace Silviooosilva\CacheerPhp\Stores\Support;

use Silviooosilva\CacheerPhp\Contracts\Clock;

/**
 * Shared, mutable registry of in-process locks for the array driver.
 *
 * A single registry is owned by an ArrayStore and shared by every lock it
 * hands out, so two locks with the same name contend correctly within the
 * process. Expiry is evaluated against the injected clock.
 */
final class InProcessLockRegistry
{
    /**
     * @var array<string, array{owner: string, expiresAt: int|null}>
     */
    private array $held = [];

    /**
     * @param Clock $clock
     */
    public function __construct(private readonly Clock $clock)
    {
    }

    /**
     * @param string $name
     * @param string $owner
     * @param ?int $expiresAt
     * @return bool
     */
    public function acquire(string $name, string $owner, ?int $expiresAt): bool
    {
        $current = $this->held[$name] ?? null;

        if ($current !== null && !$this->isExpired($current)) {
            return $current['owner'] === $owner;
        }

        $this->held[$name] = ['owner' => $owner, 'expiresAt' => $expiresAt];

        return true;
    }

    /**
     * @param string $name
     * @param string $owner
     * @return bool
     */
    public function release(string $name, string $owner): bool
    {
        $current = $this->held[$name] ?? null;

        if ($current === null || $current['owner'] !== $owner) {
            return false;
        }

        unset($this->held[$name]);

        return true;
    }

    /**
     * @param array{owner: string, expiresAt: int|null} $lock
     * @return bool
     */
    private function isExpired(array $lock): bool
    {
        return $lock['expiresAt'] !== null && $lock['expiresAt'] <= $this->clock->now();
    }
}
