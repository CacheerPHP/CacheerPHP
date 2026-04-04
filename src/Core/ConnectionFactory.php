<?php

namespace Silviooosilva\CacheerPhp\Core;

use PDO;
use PDOException;
use Silviooosilva\CacheerPhp\Enums\DatabaseDriver;
use Silviooosilva\CacheerPhp\Exceptions\ConnectionException;

/**
 * Class ConnectionFactory
 * @author Sílvio Silva <https://github.com/silviooosilva>
 * @package Silviooosilva\CacheerPhp
 */
class ConnectionFactory
{
    /**
     * Creates a new PDO instance based on the specified database configuration.
     *
     * @param array|null $database
     * @return PDO|null
     * @throws ConnectionException
     */
    public static function createConnection(?array $database = null): ?PDO
    {
        $connection = Connect::getConnection();
        $dbConf = $database ?? CACHEER_DATABASE_CONFIG[$connection->value];
        $driver = self::resolveDriver($dbConf, $connection);
        $dbConf['driver'] = $driver->dsnName();

        try {
            return new PDO(
                self::buildDsn($driver, $dbConf),
                $dbConf['username'] ?? null,
                $dbConf['passwd'] ?? null,
                self::resolveOptions($dbConf['options'] ?? [])
            );
        } catch (PDOException $exception) {
            throw ConnectionException::create($exception->getMessage(), $exception->getCode(), $exception->getPrevious());
        }
    }

    /**
     * Resolves the database driver from the configuration, supporting both 'adapter' and 'driver' keys.
     *
     * @param array $dbConf
     * @param DatabaseDriver $connection
     * @return DatabaseDriver
     */
    private static function resolveDriver(array $dbConf, DatabaseDriver $connection): DatabaseDriver
    {
        $driver = null;
        if (isset($dbConf['adapter'])) {
            $driver = DatabaseDriver::tryFrom($dbConf['adapter']);
        }
        if ($driver === null && isset($dbConf['driver'])) {
            $driver = DatabaseDriver::tryFrom($dbConf['driver']);
        }
        return $driver ?? $connection;
    }

    /**
     * Builds the DSN string for PDO based on the driver and configuration.
     *
     * @param DatabaseDriver $driver
     * @param array $dbConf
     * @return string
     */
    private static function buildDsn(DatabaseDriver $driver, array $dbConf): string
    {
        if ($driver === DatabaseDriver::SQLITE) {
            return $dbConf['driver'] . ':' . $dbConf['dbname'];
        }
        return "{$dbConf['driver']}:host={$dbConf['host']};dbname={$dbConf['dbname']};port={$dbConf['port']}";
    }

    /**
     * Resolves PDO options, converting any string constants to their actual values.
     *
     * @param array $options
     * @return array
     */
    private static function resolveOptions(array $options): array
    {
        foreach ($options as $key => $value) {
            if (is_string($value) && defined($value)) {
                $options[$key] = constant($value);
            }
        }
        return $options;
    }
}
