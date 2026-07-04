<?php

namespace Silviooosilva\CacheerPhp\Helpers;

/**
 * Class SqliteHelper
 * @author Sílvio Silva <https://github.com/silviooosilva>
 * @package Silviooosilva\CacheerPhp
 */
class SqliteHelper
{
    /**
    * Gets the path to the SQLite database file.
    *
    * @param string $database
    * @param ?string $path
    * @return string
    */
    public static function database(string $database = 'database.sqlite', ?string $path = null): string
    {
        return self::getDynamicSqliteDbPath($database, $path);
    }

    /**
    * Gets the path to the SQLite database file dynamically.
    *
    * @param  string $database
    * @param ?string $path
    * @return string
    */
    private static function getDynamicSqliteDbPath(string $database, ?string $path = null): string
    {
        $rootPath = EnvHelper::getRootPath();
        $databaseDir = is_null($path) ? $rootPath . '/database' : $rootPath . '/' . $path;
        $dbFile = $databaseDir . '/' . self::checkExtension(self::isolatePerWorker($database));

        if (!is_dir($databaseDir)) {
            self::createDatabaseDir($databaseDir);
        }
        if (!file_exists($dbFile)) {
            self::createDatabaseFile($dbFile);
        }

        return $dbFile;
    }

    /**
    * When running under ParaTest, each worker process gets its own SQLite
    * file (keyed by the TEST_TOKEN it sets) so parallel test runs don't share
    * database state. Outside ParaTest the name is returned unchanged, so normal
    * and production usage is unaffected.
    *
    * @param string $database
    * @return string
    */
    private static function isolatePerWorker(string $database): string
    {
        $token = getenv('TEST_TOKEN');
        if ($token === false || $token === '') {
            return $database;
        }

        $token = preg_replace('/[^A-Za-z0-9_]/', '', (string) $token);
        if (str_contains($database, '.sqlite')) {
            return str_replace('.sqlite', '.' . $token . '.sqlite', $database);
        }

        return $database . '.' . $token;
    }

    /**
    * Creates the database directory if it does not exist.
    *
    * @param string $databaseDir
    * @return void
    */
    private static function createDatabaseDir(string $databaseDir): void
    {
        if (!is_dir($databaseDir)) {
            mkdir($databaseDir, 0755, true);
        }
    }

    /**
    * Creates the SQLite database file if it does not exist.
    *
    * @param string $dbFile
    * @return void
    */
    private static function createDatabaseFile(string $dbFile): void
    {
        if (!file_exists($dbFile)) {
            file_put_contents($dbFile, '');
        }
    }

    /**
    * Checks if the database name has the correct extension.
    * If not, appends '.sqlite' to the name.
    *
    * @param string $database
    * @return string
    */
    private static function checkExtension(string $database): string
    {
        if (!str_contains($database, '.sqlite')) {
            return $database . '.sqlite';
        }
        return $database;
    }

}
