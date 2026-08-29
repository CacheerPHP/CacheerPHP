<?php

declare(strict_types=1);

namespace Silviooosilva\CacheerPhp\Stores\Support;

use Silviooosilva\CacheerPhp\Contracts\Clock;
use Silviooosilva\CacheerPhp\Contracts\Lock;
use Silviooosilva\CacheerPhp\Kernel\Ttl;

/**
 * A cross-process lock backed by flock() on a dedicated lock file.
 *
 * flock associates the lock with the open file description, so two instances —
 * in the same process or different ones — that each open the file contend
 * correctly. The exclusive lock is held until release() or process exit (which
 * auto-releases), so locks never orphan. The lock file is never unlinked: a
 * fresh inode would let a new acquirer lock independently of a holder of the
 * old one, briefly breaking mutual exclusion.
 */
final class FileLock implements Lock
{
    private const RETRY_MICROSECONDS = 50_000;

    /**
     * @var resource|null
     */
    private $handle = null;

    /**
     * @param string $lockFile
     * @param Clock $clock
     * @param Ttl $ttl
     */
    public function __construct(
        private readonly string $lockFile,
        private readonly Clock $clock,
        private readonly Ttl $ttl,
    ) {
    }

    /**
     * @return bool
     */
    public function acquire(): bool
    {
        if ($this->handle !== null) {
            return true;
        }

        $handle = @fopen($this->lockFile, 'c');
        if ($handle === false) {
            return false;
        }

        if (!@flock($handle, LOCK_EX | LOCK_NB)) {
            @fclose($handle);

            return false;
        }

        @ftruncate($handle, 0);
        @fwrite($handle, (string) $this->ttl->expiresAt($this->clock));
        @fflush($handle);
        $this->handle = $handle;

        return true;
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
        if ($this->handle === null) {
            return false;
        }

        @flock($this->handle, LOCK_UN);
        @fclose($this->handle);
        $this->handle = null;

        return true;
    }
}
