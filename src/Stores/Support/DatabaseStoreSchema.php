<?php

declare(strict_types=1);

namespace Silviooosilva\CacheerPhp\Stores\Support;

use InvalidArgumentException;
use PDO;
use RuntimeException;

/**
 * Explicit schema management for the database store.
 *
 * Schema creation is a deliberate operation (called by a migration or CLI step,
 * or by a test's setUp), never a hidden side effect of constructing a store.
 * Provides portable DDL for SQLite, MySQL/MariaDB, and PostgreSQL: the value
 * table, a tag membership table, and a locks table.
 */
final class DatabaseStoreSchema
{
    public static function migrate(PDO $pdo, string $table): void
    {
        self::assertSafeTableName($table);

        foreach (self::statements($pdo, $table) as $statement) {
            $pdo->exec($statement);
        }
    }

    /**
     * The DDL that migrate() would run, for previews (CLI dry runs) and docs.
     *
     * @return list<string>
     */
    public static function sqlFor(PDO $pdo, string $table): array
    {
        self::assertSafeTableName($table);

        return self::statements($pdo, $table);
    }

    public static function drop(PDO $pdo, string $table): void
    {
        self::assertSafeTableName($table);

        foreach ([$table . '_tags', $table . '_locks', $table] as $name) {
            $pdo->exec(sprintf('DROP TABLE IF EXISTS %s', $name));
        }
    }

    /**
     * @return list<string>
     */
    private static function statements(PDO $pdo, string $table): array
    {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        return match ($driver) {
            'sqlite' => self::sqlite($table),
            'mysql'  => self::mysql($table),
            'pgsql'  => self::pgsql($table),
            default  => throw new RuntimeException(sprintf('Unsupported database driver "%s".', (string) $driver)),
        };
    }

    /**
     * @return list<string>
     */
    private static function sqlite(string $t): array
    {
        return [
            "CREATE TABLE IF NOT EXISTS {$t} (
                cache_key TEXT PRIMARY KEY,
                scope TEXT NOT NULL,
                key_value TEXT NOT NULL,
                value TEXT NOT NULL,
                created_at INTEGER NOT NULL,
                expires_at INTEGER NULL
            )",
            "CREATE INDEX IF NOT EXISTS idx_{$t}_expires ON {$t} (expires_at)",
            "CREATE INDEX IF NOT EXISTS idx_{$t}_scope ON {$t} (scope)",
            "CREATE TABLE IF NOT EXISTS {$t}_tags (tag TEXT NOT NULL, cache_key TEXT NOT NULL)",
            "CREATE INDEX IF NOT EXISTS idx_{$t}_tags_tag ON {$t}_tags (tag)",
            "CREATE TABLE IF NOT EXISTS {$t}_locks (lock_name TEXT PRIMARY KEY, owner TEXT NOT NULL, expires_at INTEGER NOT NULL)",
        ];
    }

    /**
     * @return list<string>
     */
    private static function mysql(string $t): array
    {
        return [
            "CREATE TABLE IF NOT EXISTS {$t} (
                cache_key VARCHAR(255) NOT NULL PRIMARY KEY,
                scope VARCHAR(255) NOT NULL,
                key_value TEXT NOT NULL,
                value LONGTEXT NOT NULL,
                created_at BIGINT NOT NULL,
                expires_at BIGINT NULL,
                INDEX idx_{$t}_expires (expires_at),
                INDEX idx_{$t}_scope (scope)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS {$t}_tags (
                tag VARCHAR(255) NOT NULL,
                cache_key VARCHAR(255) NOT NULL,
                INDEX idx_{$t}_tags_tag (tag)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS {$t}_locks (
                lock_name VARCHAR(255) NOT NULL PRIMARY KEY,
                owner VARCHAR(255) NOT NULL,
                expires_at BIGINT NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        ];
    }

    /**
     * @return list<string>
     */
    private static function pgsql(string $t): array
    {
        return [
            "CREATE TABLE IF NOT EXISTS {$t} (
                cache_key VARCHAR(255) PRIMARY KEY,
                scope VARCHAR(255) NOT NULL,
                key_value TEXT NOT NULL,
                value TEXT NOT NULL,
                created_at BIGINT NOT NULL,
                expires_at BIGINT NULL
            )",
            "CREATE INDEX IF NOT EXISTS idx_{$t}_expires ON {$t} (expires_at)",
            "CREATE INDEX IF NOT EXISTS idx_{$t}_scope ON {$t} (scope)",
            "CREATE TABLE IF NOT EXISTS {$t}_tags (tag VARCHAR(255) NOT NULL, cache_key VARCHAR(255) NOT NULL)",
            "CREATE INDEX IF NOT EXISTS idx_{$t}_tags_tag ON {$t}_tags (tag)",
            "CREATE TABLE IF NOT EXISTS {$t}_locks (lock_name VARCHAR(255) PRIMARY KEY, owner VARCHAR(255) NOT NULL, expires_at BIGINT NOT NULL)",
        ];
    }

    public static function assertSafeTableName(string $table): void
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $table) !== 1) {
            throw new InvalidArgumentException(sprintf('Unsafe cache table name "%s".', $table));
        }
    }
}
