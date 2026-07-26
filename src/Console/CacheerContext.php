<?php

declare(strict_types=1);

namespace Silviooosilva\CacheerPhp\Console;

use PDO;
use Silviooosilva\CacheerPhp\Contracts\Store;

/**
 * The resolved application context a command operates on: the target store and,
 * for database operations, the PDO connection and table. Built from the
 * project's explicit `cacheer.config.php` bootstrap.
 */
final class CacheerContext
{
    public function __construct(
        private readonly Store $store,
        private readonly ?PDO $pdo = null,
        private readonly string $table = 'cacheer_store',
    ) {
    }

    public function store(): Store
    {
        return $this->store;
    }

    public function pdo(): ?PDO
    {
        return $this->pdo;
    }

    public function table(): string
    {
        return $this->table;
    }

    /**
     * A short, human-readable description of the keyspace a mutation targets,
     * for confirmations and dry runs.
     */
    public function keyspace(): string
    {
        return (new \ReflectionClass($this->store))->getShortName();
    }
}
