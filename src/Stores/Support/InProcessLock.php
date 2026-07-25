<?php

declare(strict_types=1);

namespace Silviooosilva\CacheerPhp\Stores\Support;

use Silviooosilva\CacheerPhp\Contracts\Clock;
use Silviooosilva\CacheerPhp\Contracts\Lock;
use Silviooosilva\CacheerPhp\Kernel\Ttl;

/**
 * A single named lock backed by an InProcessLockRegistry.
 *
 * Each instance owns a unique token, so release() only frees a lock this
 * instance holds. block() retries through the injected clock, which keeps it
 * deterministic under a fake clock in tests.
 */
final class InProcessLock implements Lock
{
    private const RETRY_MICROSECONDS = 50_000;

    private readonly string $owner;

    public function __construct(
        private readonly InProcessLockRegistry $registry,
        private readonly Clock $clock,
        private readonly string $name,
        private readonly Ttl $ttl,
    ) {
        $this->owner = bin2hex(random_bytes(8));
    }

    public function acquire(): bool
    {
        return $this->registry->acquire($this->name, $this->owner, $this->ttl->expiresAt($this->clock));
    }

    public function block(float $seconds): bool
    {
        $deadline = $this->clock->nowFloat() + $seconds;

        while (true) {
            if ($this->acquire()) {
                return true;
            }

            if ($this->clock->nowFloat() >= $deadline) {
                return false;
            }

            $this->clock->sleep(self::RETRY_MICROSECONDS);
        }
    }

    public function release(): bool
    {
        return $this->registry->release($this->name, $this->owner);
    }
}
