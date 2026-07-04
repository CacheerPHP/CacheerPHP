<?php

use Dotenv\Dotenv;
use Silviooosilva\CacheerPhp\Core\Connect;
use Silviooosilva\CacheerPhp\Enums\DatabaseDriver;
use Silviooosilva\CacheerPhp\Helpers\EnvHelper;
use Silviooosilva\CacheerPhp\Helpers\SqliteHelper;

$rootPath = EnvHelper::getRootPath();
$envFile = $rootPath . DIRECTORY_SEPARATOR . '.env';

if (!file_exists($envFile)) {
    $message = implode(PHP_EOL, [
        '',
        '[CacheerPHP] No .env file found at: ' . $rootPath,
        'Please, first of all, create one.',
        'You can use the example file as a starting point:',
        'vendor/silviooosilva/cacheer-php',
        'move the .env.example to your project root and rename it to .env',
        'Or simply run the following command in your project root:',
        'cp .env.example .env',
        '',
    ]);
    throw new \RuntimeException($message);
}

$dotenv = Dotenv::createImmutable($rootPath);
$dotenv->load();

/**
 * Read a configuration value from any environment source.
 *
 * With Dotenv's immutable loader, a variable already present in the real
 * environment (e.g. exported in Docker/CI, or propagated to ParaTest worker
 * processes) is NOT copied into $_ENV. Reading only $_ENV would then miss it
 * and silently fall back to the default. Check $_ENV, $_SERVER, and getenv().
 */
$envValue = static function (string $key, ?string $default = null): ?string {
    if (array_key_exists($key, $_ENV)) {
        return $_ENV[$key];
    }
    if (array_key_exists($key, $_SERVER)) {
        return $_SERVER[$key];
    }
    $value = getenv($key);
    return $value === false ? $default : $value;
};

$connectionValue = strtolower($envValue('DB_CONNECTION', DatabaseDriver::MYSQL->value));
$connectionDriver = DatabaseDriver::tryFrom($connectionValue) ?? DatabaseDriver::MYSQL;
$Host = $envValue('DB_HOST', 'localhost');
$Port = $envValue('DB_PORT', '3306');
$DBName = $envValue('DB_DATABASE', 'cacheer_db');
$User = $envValue('DB_USERNAME', 'root');
$Password = $envValue('DB_PASSWORD', '');

// Retrieve Redis environment variables
$redisClient = $envValue('REDIS_CLIENT', '');
$redisHost = $envValue('REDIS_HOST', 'localhost');
$redisPassword = $envValue('REDIS_PASSWORD', '');
$redisPort = $envValue('REDIS_PORT', '6379');
$redisNamespace = $envValue('REDIS_NAMESPACE', '');
$cacheTable = $envValue('CACHEER_TABLE', 'cacheer_table');

Connect::setConnection($connectionDriver);

$commonPdoOptions = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
    PDO::ATTR_CASE               => PDO::CASE_NATURAL,
];

$mysqlConfig = [
    'adapter'  => DatabaseDriver::MYSQL->value,
    'driver'   => DatabaseDriver::MYSQL->dsnName(),
    'host'     => $Host,
    'port'     => $Port,
    'dbname'   => $DBName,
    'username' => $User,
    'passwd'   => $Password,
    'options'  => array_replace(
        [Pdo\Mysql::ATTR_INIT_COMMAND => 'SET NAMES utf8'],
        $commonPdoOptions,
    ),
];

$mariaDbConfig = $mysqlConfig;
$mariaDbConfig['adapter'] = DatabaseDriver::MARIADB->value;
$mariaDbConfig['driver'] = DatabaseDriver::MARIADB->dsnName();

// Database configuration array
define('CACHEER_DATABASE_CONFIG', [
    DatabaseDriver::MYSQL->value   => $mysqlConfig,
    DatabaseDriver::MARIADB->value => $mariaDbConfig,
    DatabaseDriver::SQLITE->value  => [
        'adapter' => DatabaseDriver::SQLITE->value,
        'driver'  => DatabaseDriver::SQLITE->dsnName(),
        'dbname'  => SqliteHelper::database(),
        'options' => $commonPdoOptions,
    ],
    DatabaseDriver::PGSQL->value => [
        'adapter'  => DatabaseDriver::PGSQL->value,
        'driver'   => DatabaseDriver::PGSQL->dsnName(),
        'host'     => $Host,
        'port'     => $Port,
        'dbname'   => $DBName,
        'username' => $User,
        'passwd'   => $Password,
        'options'  => $commonPdoOptions,
    ],
]);

// Redis configuration array
define('REDIS_CONNECTION_CONFIG', [
    'REDIS_CLIENT'    => $redisClient,
    'REDIS_HOST'      => $redisHost,
    'REDIS_PASSWORD'  => $redisPassword,
    'REDIS_PORT'      => $redisPort,
    'REDIS_NAMESPACE' => $redisNamespace,
]);

// Cache table name for database driver
if (!defined('CACHEER_TABLE')) {
    define('CACHEER_TABLE', $cacheTable);
}
