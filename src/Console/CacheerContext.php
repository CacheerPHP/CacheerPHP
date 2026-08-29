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
    /**
     * @param Store $store
     * @param ?PDO $pdo
     * @param string $table
     */
    public function __construct(
        private readonly Store $store,
        private readonly ?PDO $pdo = null,
        private readonly string $table = 'cacheer_store',
    ) {
    }

    /**
     * @return Store
     */
    public function store(): Store
    {
        return $this->store;
    }

    /**
     * @return ?PDO
     */
    public function pdo(): ?PDO
    {
        return $this->pdo;
    }

    /**
     * @return string
     */
    public function table(): string
    {
        return $this->table;
    }

    /**
     * A short, human-readable description of the keyspace a mutation targets,
     * for confirmations and dry runs.
     *
     * @return string
     */
    public function keyspace(): string
    {
        return (new \ReflectionClass($this->store))->getShortName();
    }
}
