<?php

namespace Silviooosilva\CacheerPhp\Boot;

use Dotenv\Dotenv;
use PDO;
use Silviooosilva\CacheerPhp\Enums\DatabaseDriver;
use Silviooosilva\CacheerPhp\Helpers\EnvHelper;
use Silviooosilva\CacheerPhp\Helpers\SqliteHelper;

/**
 * Class RuntimeConfig
 *
 * Lazy replacement for the former Boot/Configs.php eager bootstrap.
 *
 * Nothing here runs at autoload time. Configuration is resolved the first
 * time a driver actually needs it, and memoized afterwards:
 *  - A project .env file is optional. When present it is loaded once
 *    (immutably, so real environment variables always win); when absent the
 *    documented defaults apply and no exception is thrown.
 *  - Database config is built per driver on demand, so side effects such as
 *    creating the SQLite database file only happen for users of that driver.
 *  - No global constants are defined. A user-defined CACHEER_TABLE constant
 *    is still honored as an override for the cache table name.
 *
 * @author Sílvio Silva <https://github.com/silviooosilva>
 * @package Silviooosilva\CacheerPhp
 */
final class RuntimeConfig
{
    /**
     * @var bool
     */
    private static bool $envLoaded = false;

    /**
     * Per-driver memoized database configuration.
     *
     * @var array<string, array<string, mixed>>
     */
    private static array $databaseConfig = [];

    /**
     * @var array<string, mixed>|null
     */
    private static ?array $redisConfig = null;

    /**
     * Reads a configuration value from any environment source.
     *
     * Checks $_ENV, $_SERVER, and getenv() so values work whether they come
     * from a .env file, a Docker/CI environment, or a worker process. Loads
     * the project .env (if any) on first call.
     *
     * @param string      $key
     * @param string|null $default
     * @return string|null
     */
    public static function env(string $key, ?string $default = null): ?string
    {
        self::loadEnvFileOnce();

        if (array_key_exists($key, $_ENV) && is_string($_ENV[$key])) {
            return $_ENV[$key];
        }
        if (array_key_exists($key, $_SERVER) && is_string($_SERVER[$key])) {
            return $_SERVER[$key];
        }
        $value = getenv($key);
        return $value === false ? $default : $value;
    }

    /**
     * Default database driver, resolved from DB_CONNECTION (fallback: mysql).
     *
     * @return DatabaseDriver
     */
    public static function defaultDatabaseDriver(): DatabaseDriver
    {
        $connection = strtolower((string) self::env('DB_CONNECTION', DatabaseDriver::MYSQL->value));
        return DatabaseDriver::tryFrom($connection) ?? DatabaseDriver::MYSQL;
    }

    /**
     * Connection configuration for a database driver.
     *
     * Same shape as the entries of the former CACHEER_DATABASE_CONFIG
     * constant: adapter, driver, host/port/dbname/username/passwd (where
     * applicable), and PDO options.
     *
     * @param DatabaseDriver $driver
     * @return array<string, mixed>
     */
    public static function database(DatabaseDriver $driver): array
    {
        return self::$databaseConfig[$driver->value] ??= self::buildDatabaseConfig($driver);
    }

    /**
     * Redis connection configuration (host, port, password, namespace, client, database).
     *
     * @return array{client: string, host: string, port: int, password: string, namespace: string, database: int}
     */
    public static function redis(): array
    {
        return self::$redisConfig ??= [
            'client'    => (string) self::env('REDIS_CLIENT', ''),
            'host'      => (string) self::env('REDIS_HOST', 'localhost'),
            'port'      => (int) self::env('REDIS_PORT', '6379'),
            'password'  => (string) self::env('REDIS_PASSWORD', ''),
            'namespace' => (string) self::env('REDIS_NAMESPACE', ''),
            'database'  => (int) self::env('REDIS_DB', '0'),
        ];
    }

    /**
     * Cache table name for the database driver.
     *
     * Resolution order: user-defined CACHEER_TABLE constant, CACHEER_TABLE
     * environment variable, default 'cacheer_table'.
     *
     * @return string
     */
    public static function table(): string
    {
        if (defined('CACHEER_TABLE')) {
            return (string) constant('CACHEER_TABLE');
        }
        return (string) self::env('CACHEER_TABLE', 'cacheer_table');
    }

    /**
     * Clears all memoized state. Intended for tests.
     *
     * @return void
     */
    public static function reset(): void
    {
        self::$envLoaded = false;
        self::$databaseConfig = [];
        self::$redisConfig = null;
    }

    /**
     * Loads the project .env file once, when one exists.
     *
     * Uses Dotenv's immutable loader so variables already present in the real
     * environment are never overridden. Missing .env files and a missing
     * phpdotenv package are both fine: defaults and real env vars still apply.
     *
     * @return void
     */
    private static function loadEnvFileOnce(): void
    {
        if (self::$envLoaded) {
            return;
        }
        self::$envLoaded = true;

        $rootPath = EnvHelper::getRootPath();
        if (class_exists(Dotenv::class) && file_exists($rootPath . DIRECTORY_SEPARATOR . '.env')) {
            Dotenv::createImmutable($rootPath)->safeLoad();
        }
    }

    /**
     * Builds the connection configuration for one database driver.
     *
     * @param DatabaseDriver $driver
     * @return array<string, mixed>
     */
    private static function buildDatabaseConfig(DatabaseDriver $driver): array
    {
        if ($driver === DatabaseDriver::SQLITE) {
            return [
                'adapter' => $driver->value,
                'driver'  => $driver->dsnName(),
                'dbname'  => SqliteHelper::database(),
                'options' => self::commonPdoOptions(),
            ];
        }

        $config = [
            'adapter'  => $driver->value,
            'driver'   => $driver->dsnName(),
            'host'     => (string) self::env('DB_HOST', 'localhost'),
            'port'     => (string) self::env('DB_PORT', '3306'),
            'dbname'   => (string) self::env('DB_DATABASE', 'cacheer_db'),
            'username' => (string) self::env('DB_USERNAME', 'root'),
            'passwd'   => (string) self::env('DB_PASSWORD', ''),
            'options'  => self::commonPdoOptions(),
        ];

        if ($driver->isMysqlFamily() && defined('Pdo\Mysql::ATTR_INIT_COMMAND')) {
            $config['options'][Pdo\Mysql::ATTR_INIT_COMMAND] = 'SET NAMES utf8';
        }

        return $config;
    }

    /**
     * PDO options shared by every driver.
     *
     * @return array<int, int>
     */
    private static function commonPdoOptions(): array
    {
        return [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
            PDO::ATTR_CASE               => PDO::CASE_NATURAL,
        ];
    }
}
