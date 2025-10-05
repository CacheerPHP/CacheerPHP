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
     * @return array
     */
    private static function getMigrationQueries(PDO $connection, ?string $tableName = null): array
    {
        $driver = DatabaseDriver::tryFrom($connection->getAttribute(PDO::ATTR_DRIVER_NAME));
        $createdAtDefault = ($driver === DatabaseDriver::PGSQL) ? 'DEFAULT NOW()' : 'DEFAULT CURRENT_TIMESTAMP';
        $table = $tableName ?: (defined('CACHEER_TABLE') ? CACHEER_TABLE : 'cacheer_table');

        if ($driver === DatabaseDriver::SQLITE) {
            $query = "
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
        } elseif ($driver === DatabaseDriver::PGSQL) {
            $query = "
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
        } else {
            $query = "
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
        return array_filter(array_map('trim', explode(';', $query)));
    }
}
