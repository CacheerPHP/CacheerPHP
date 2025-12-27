<?php

namespace Silviooosilva\CacheerPhp\Core;

use PDO;
use PDOException;
use Silviooosilva\CacheerPhp\Enums\DatabaseDriver;

/**
 * Class MigrationManager
 * @author Sílvio Silva <https://github.com/silviooosilva>
 * @package Silviooosilva\CacheerPhp
 */
class MigrationManager
{
    /**
     * Executes the migration process for the database.
     * 
     * @param PDO $connection
     * @return void
     */
    public static function migrate(PDO $connection, ?string $tableName = null): void
    {
        try {
            self::prepareDatabase($connection);
            $queries = self::getMigrationQueries($connection, $tableName);
            foreach ($queries as $query) {
                if (trim($query)) {
                    $connection->exec($query);
                }
            }
        } catch (PDOException $exception) {
            throw new PDOException($exception->getMessage(), $exception->getCode());
        }
    }

    /**
     * Prepares the database connection for migration.
     * 
     * @param PDO $connection
     * @return void
     */
    private static function prepareDatabase(PDO $connection): void
    {
        $driver = DatabaseDriver::tryFrom($connection->getAttribute(PDO::ATTR_DRIVER_NAME));
        if ($driver !== DatabaseDriver::SQLITE) {
            $dbname = CACHEER_DATABASE_CONFIG[Connect::getConnection()->value]['dbname'];
            $connection->exec("USE $dbname");
        }
    }

    /**
     * Generates the SQL queries needed for the migration based on the database driver.
     * 
     * @param PDO $connection
     * @param string|null $tableName
     * @return array
     */
    private static function getMigrationQueries(PDO $connection, ?string $tableName = null): array
    {
        $driver = self::resolveDriver($connection);
        $createdAtDefault = self::createdAtDefault($driver);
        $table = self::resolveTableName($tableName);

        $query = self::buildSchemaQuery($driver, $table, $createdAtDefault);
        return self::splitQueries($query);
    }

    /**
     * @param PDO $connection
     * @return DatabaseDriver|null
     */
    private static function resolveDriver(PDO $connection): ?DatabaseDriver
    {
        return DatabaseDriver::tryFrom($connection->getAttribute(PDO::ATTR_DRIVER_NAME));
    }

    /**
     * @param string|null $tableName
     * @return string
     */
    private static function resolveTableName(?string $tableName): string
    {
        if ($tableName) {
            return $tableName;
        }
        if (defined('CACHEER_TABLE')) {
            return CACHEER_TABLE;
        }
        return 'cacheer_table';
    }

    /**
     * @param DatabaseDriver|null $driver
     * @return string
     */
    private static function createdAtDefault(?DatabaseDriver $driver): string
    {
        return ($driver === DatabaseDriver::PGSQL) ? 'DEFAULT NOW()' : 'DEFAULT CURRENT_TIMESTAMP';
    }

    /**
     * @param DatabaseDriver|null $driver
     * @param string $table
     * @param string $createdAtDefault
     * @return string
     */
    private static function buildSchemaQuery(?DatabaseDriver $driver, string $table, string $createdAtDefault): string
    {
        if ($driver === DatabaseDriver::SQLITE) {
            return self::sqliteSchema($table, $createdAtDefault);
        }
        if ($driver === DatabaseDriver::PGSQL) {
            return self::pgsqlSchema($table, $createdAtDefault);
        }
        return self::mysqlSchema($table, $createdAtDefault);
    }

    /**
     * @param string $table
     * @param string $createdAtDefault
     * @return string
     */
    private static function sqliteSchema(string $table, string $createdAtDefault): string
    {
        return "
                CREATE TABLE IF NOT EXISTS {$table} (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    cacheKey VARCHAR(255) NOT NULL,
                    cacheData TEXT NOT NULL,
                    cacheNamespace VARCHAR(255),
                    expirationTime DATETIME NOT NULL,
                    created_at DATETIME $createdAtDefault,
                    UNIQUE(cacheKey, cacheNamespace)
                );
                CREATE INDEX IF NOT EXISTS idx_{$table}_cacheKey ON {$table} (cacheKey);
                CREATE INDEX IF NOT EXISTS idx_{$table}_cacheNamespace ON {$table} (cacheNamespace);
                CREATE INDEX IF NOT EXISTS idx_{$table}_expirationTime ON {$table} (expirationTime);
                CREATE INDEX IF NOT EXISTS idx_{$table}_key_namespace ON {$table} (cacheKey, cacheNamespace);
            ";
    }

    /**
     * @param string $table
     * @param string $createdAtDefault
     * @return string
     */
    private static function pgsqlSchema(string $table, string $createdAtDefault): string
    {
        return "
                CREATE TABLE IF NOT EXISTS {$table} (
                    id SERIAL PRIMARY KEY,
                    cacheKey VARCHAR(255) NOT NULL,
                    cacheData TEXT NOT NULL,
                    cacheNamespace VARCHAR(255),
                    expirationTime TIMESTAMP NOT NULL,
                    created_at TIMESTAMP $createdAtDefault,
                    UNIQUE(cacheKey, cacheNamespace)
                );
                CREATE INDEX IF NOT EXISTS idx_{$table}_cacheKey ON {$table} (cacheKey);
                CREATE INDEX IF NOT EXISTS idx_{$table}_cacheNamespace ON {$table} (cacheNamespace);
                CREATE INDEX IF NOT EXISTS idx_{$table}_expirationTime ON {$table} (expirationTime);
                CREATE INDEX IF NOT EXISTS idx_{$table}_key_namespace ON {$table} (cacheKey, cacheNamespace);
            ";
    }

    /**
     * @param string $table
     * @param string $createdAtDefault
     * @return string
     */
    private static function mysqlSchema(string $table, string $createdAtDefault): string
    {
        return "
                CREATE TABLE IF NOT EXISTS {$table} (
                    id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
                    cacheKey VARCHAR(255) NOT NULL,
                    cacheData LONGTEXT NOT NULL,
                    cacheNamespace VARCHAR(255) NULL,
                    expirationTime DATETIME NOT NULL,
                    created_at TIMESTAMP $createdAtDefault,
                    UNIQUE KEY unique_cache_key_namespace (cacheKey, cacheNamespace),
                    KEY idx_{$table}_cacheKey (cacheKey),
                    KEY idx_{$table}_cacheNamespace (cacheNamespace),
                    KEY idx_{$table}_expirationTime (expirationTime),
                    KEY idx_{$table}_key_namespace (cacheKey, cacheNamespace)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
            ";
    }

    /**
     * @param string $query
     * @return array
     */
    private static function splitQueries(string $query): array
    {
        return array_filter(array_map('trim', explode(';', $query)));
    }
}
