<?php

namespace Silviooosilva\CacheerPhp\Core;

use PDO;
use PDOException;
use Silviooosilva\CacheerPhp\Enums\DatabaseDriver;
use Silviooosilva\CacheerPhp\Exceptions\ConnectionException;

/**
 * Class Connect
 * @author Sílvio Silva <https://github.com/silviooosilva>
 * @package Silviooosilva\CacheerPhp
 */
class Connect
{
    /**
    * Active database driver for new connections.
    */
    public static DatabaseDriver $connection = DatabaseDriver::SQLITE;

    /**
    * Holds the last error encountered during connection attempts.
    *
    * @var PDOException|null
    */
    private static ?PDOException $error = null;


    /**
     * Creates a new PDO instance based on the specified database configuration.
     *
     * @param array|null $database
     * @return PDO|null
     * @throws ConnectionException
     */
    public static function getInstance(?array $database = null): ?PDO
    {
        $pdo = ConnectionFactory::createConnection($database);
        if ($pdo) {
            MigrationManager::migrate($pdo);
        }
        return $pdo;
    }

    /**
     * Sets the connection type for the database.
     *
     * @param DatabaseDriver|string $connection
     * @return void
     * @throws ConnectionException
     */
    public static function setConnection(DatabaseDriver|string $connection): void
    {
        $driver = $connection instanceof DatabaseDriver
            ? $connection
            : DatabaseDriver::tryFrom(strtolower($connection));

        if ($driver === null) {
            $labels = DatabaseDriver::labels();
            throw ConnectionException::create('Only [' . implode(', ', $labels) . '] are available at the moment...');
        }

        self::$connection = $driver;
    }

    /**
    * Gets the current connection type.
    *
    * @return DatabaseDriver
     */
    public static function getConnection(): DatabaseDriver
    {
        return self::$connection;
    }

    /**
    * Returns the last error encountered during connection attempts.\
    * 
    * @return PDOException|null
    */
    public static function getError(): ?PDOException
    {
        return self::$error;
    }
    
    /**
     * Prevents instantiation of the Connect class.
     * This class is designed to be used statically, so it cannot be instantiated.
     * 
     * @return void
    */    
    private function __construct() {}

    /**
    * Prevents cloning of the Connect instance.
    *
    * @return void
    */
    private function __clone() {}
}
