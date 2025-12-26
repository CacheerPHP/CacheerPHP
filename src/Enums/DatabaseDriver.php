<?php

namespace Silviooosilva\CacheerPhp\Enums;

enum DatabaseDriver: string
{
    case MYSQL = 'mysql';
    case MARIADB = 'mariadb';
    case SQLITE = 'sqlite';
    case PGSQL = 'pgsql';

    /**
     * Human friendly label for error/help messages.
     * 
     * @return string
     */
    public function label(): string
    {
        return match ($this) {
            self::MYSQL => 'MySQL(mysql)',
            self::MARIADB => 'MariaDB(mariadb)',
            self::SQLITE => 'SQLite(sqlite)',
            self::PGSQL => 'PgSQL(pgsql)',
        };
    }

    /**
     * PDO DSN identifier for the driver.
     * 
     * @return string
     */
    public function dsnName(): string
    {
        return match ($this) {
            self::MARIADB => self::MYSQL->value,
            default => $this->value,
        };
    }

    /**
     * Whether the driver behaves like MySQL for SQL syntax decisions.
     * 
     * @return bool
     */
    public function isMysqlFamily(): bool
    {
        return $this === self::MYSQL || $this === self::MARIADB;
    }

    /**
     * Handy helper for building allow-list messages.
     *
     * @return array<int, string>
     */
    public static function labels(): array
    {
        return array_map(static fn (self $driver) => $driver->label(), self::cases());
    }
}

