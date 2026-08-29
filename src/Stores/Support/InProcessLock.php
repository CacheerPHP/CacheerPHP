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

    /**
     * @var string
     */
    private readonly string $owner;

    /**
     * @param InProcessLockRegistry $registry
     * @param Clock $clock
     * @param string $name
     * @param Ttl $ttl
     */
    public function __construct(
        private readonly InProcessLockRegistry $registry,
        private readonly Clock $clock,
        private readonly string $name,
        private readonly Ttl $ttl,
    ) {
        $this->owner = bin2hex(random_bytes(8));
    }

    /**
     * @return bool
     */
    public function acquire(): bool
    {
        return $this->registry->acquire($this->name, $this->owner, $this->ttl->expiresAt($this->clock));
    }

    /**
     * @param float $seconds
     * @return bool
     */
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

    /**
     * @return bool
     */
    public function release(): bool
    {
        return $this->registry->release($this->name, $this->owner);
    }
}
