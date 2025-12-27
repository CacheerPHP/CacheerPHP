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

        $driver = null;
        if (isset($dbConf['adapter'])) {
            $driver = DatabaseDriver::tryFrom($dbConf['adapter']);
        }
        if ($driver === null && isset($dbConf['driver'])) {
            $driver = DatabaseDriver::tryFrom($dbConf['driver']);
        }
        $driver ??= $connection;

        $dsnDriver = $driver->dsnName();
        $dbConf['driver'] = $dsnDriver;

        if ($driver === DatabaseDriver::SQLITE) {
            $dbName = $dbConf['dbname'];
            $dbDsn = $dsnDriver . ':' . $dbName;
        } else {
            $dbDsn = "{$dsnDriver}:host={$dbConf['host']};dbname={$dbConf['dbname']};port={$dbConf['port']}";
        }

        try {
            $options = $dbConf['options'] ?? [];
            foreach ($options as $key => $value) {
                if (is_string($value) && defined($value)) {
                    $options[$key] = constant($value);
                }
            }
            return new PDO($dbDsn, $dbConf['username'] ?? null, $dbConf['passwd'] ?? null, $options);
        } catch (PDOException $exception) {
            throw ConnectionException::create($exception->getMessage(), $exception->getCode(), $exception->getPrevious());
        }
    }
}
