<?php

use Dotenv\Dotenv;
use Silviooosilva\CacheerPhp\Core\Connect;
use Silviooosilva\CacheerPhp\Enums\DatabaseDriver;
use Silviooosilva\CacheerPhp\Helpers\EnvHelper;
use Silviooosilva\CacheerPhp\Helpers\SqliteHelper;


$rootPath = EnvHelper::getRootPath();
$dotenv = Dotenv::createImmutable($rootPath);
$dotenv->load();

$connectionValue = strtolower($_ENV['DB_CONNECTION'] ?? DatabaseDriver::MYSQL->value);
$connectionDriver = DatabaseDriver::tryFrom($connectionValue) ?? DatabaseDriver::MYSQL;
$Host       = $_ENV['DB_HOST'] ?? 'localhost';
$Port       = $_ENV['DB_PORT'] ?? '3306';
$DBName     = $_ENV['DB_DATABASE'] ?? 'cacheer_db';
$User       = $_ENV['DB_USERNAME'] ?? 'root';
$Password   = $_ENV['DB_PASSWORD'] ?? '';

// Retrieve Redis environment variables
$redisClient    = $_ENV['REDIS_CLIENT'] ?? '';
$redisHost      = $_ENV['REDIS_HOST'] ?? 'localhost';
$redisPassword  = $_ENV['REDIS_PASSWORD'] ?? '';
$redisPort      = $_ENV['REDIS_PORT'] ?? '6379';
$redisNamespace = $_ENV['REDIS_NAMESPACE'] ?? '';
$cacheTable     = $_ENV['CACHEER_TABLE'] ?? 'cacheer_table';

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
        [PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8'],
        $commonPdoOptions
    ),
];

$mariaDbConfig = $mysqlConfig;
$mariaDbConfig['adapter'] = DatabaseDriver::MARIADB->value;
$mariaDbConfig['driver']  = DatabaseDriver::MARIADB->dsnName();

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
    DatabaseDriver::PGSQL->value   => [
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
    'REDIS_CLIENT'   => $redisClient,
    'REDIS_HOST'     => $redisHost,
    'REDIS_PASSWORD' => $redisPassword,
    'REDIS_PORT'     => $redisPort,
    'REDIS_NAMESPACE'=> $redisNamespace
]);

// Cache table name for database driver
if (!defined('CACHEER_TABLE')) {
    define('CACHEER_TABLE', $cacheTable);
}
