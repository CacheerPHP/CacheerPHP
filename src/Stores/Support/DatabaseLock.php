<?php

declare(strict_types=1);

namespace Silviooosilva\CacheerPhp\Stores\Support;

use PDO;
use Silviooosilva\CacheerPhp\Contracts\Clock;
use Silviooosilva\CacheerPhp\Contracts\Lock;
use Silviooosilva\CacheerPhp\Kernel\Ttl;

/**
 * A cross-process lock backed by a row in the locks table.
 *
 * The primary key on lock_name is the atomic gate: exactly one INSERT for a
 * given name can succeed. An expired row is reclaimed before acquiring, and
 * release only removes a row this instance owns.
 */
final class DatabaseLock implements Lock
{
    private const RETRY_MICROSECONDS = 50_000;

    private readonly string $owner;

    private bool $held = false;

    public function __construct(
        private readonly PDO $pdo,
        private readonly string $table,
        private readonly Clock $clock,
        private readonly string $name,
        private readonly Ttl $ttl,
    ) {
        $this->owner = bin2hex(random_bytes(16));
    }

    public function acquire(): bool
    {
        $delete = $this->pdo->prepare("DELETE FROM {$this->table}_locks WHERE lock_name = :name AND expires_at <= :now");
        $delete->execute([':name' => $this->name, ':now' => $this->clock->now()]);

        $expiresAt = $this->ttl->expiresAt($this->clock) ?? PHP_INT_MAX;

        try {
            $insert = $this->pdo->prepare(
                "INSERT INTO {$this->table}_locks (lock_name, owner, expires_at) VALUES (:name, :owner, :expires)",
            );
            $insert->execute([':name' => $this->name, ':owner' => $this->owner, ':expires' => $expiresAt]);
        } catch (\PDOException) {
            return false;
        }

        $this->held = true;

        return true;
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
        if (!$this->held) {
            return false;
        }

        $delete = $this->pdo->prepare("DELETE FROM {$this->table}_locks WHERE lock_name = :name AND owner = :owner");
        $delete->execute([':name' => $this->name, ':owner' => $this->owner]);
        $this->held = false;

        return $delete->rowCount() > 0;
    }
}
