<?php

namespace Silviooosilva\CacheerPhp\Repositories;

use PDO;
use Silviooosilva\CacheerPhp\Core\Connect;
use Silviooosilva\CacheerPhp\Enums\DatabaseDriver;

/**
 * Class CacheDatabaseRepository
 * @author Sílvio Silva <https://github.com/silviooosilva>
 * @package Silviooosilva\CacheerPhp
 */
class CacheDatabaseRepository
{

    /** @var ?PDO */
    private ?PDO $connection = null;

    /** @var string */
    private string $table = 'cacheer_table';

   
    /**
     * CacheDatabaseRepository constructor.
     * Initializes the database connection using the Connect class.
     * 
     */
    public function __construct(string $table = 'cacheer_table')
    {
        $this->connection = Connect::getInstance();
        $this->table = $table ?: 'cacheer_table';
    }


    /**
     * Stores cache data in the database.
     * 
     * @param string $cacheKey
     * @param mixed  $cacheData
     * @param string $namespace
     * @param string|int $ttl
     * @return bool
     */
    public function store(string $cacheKey, mixed $cacheData, string $namespace, string|int $ttl = 3600): bool
    {
        if (!empty($this->retrieve($cacheKey, $namespace))) {
            return $this->update($cacheKey, $cacheData, $namespace);
        }

        $expirationTime = date('Y-m-d H:i:s', time() + $ttl);
        $createdAt = date('Y-m-d H:i:s');

        $stmt = $this->connection->prepare(
            "INSERT INTO {$this->table} (cacheKey, cacheData, cacheNamespace, expirationTime, created_at) 
            VALUES (:cacheKey, :cacheData, :namespace, :expirationTime, :createdAt)"
        );
        $stmt->bindValue(':cacheKey', $cacheKey);
        $stmt->bindValue(':cacheData', $this->serialize($cacheData));
        $stmt->bindValue(':namespace', $namespace);
        $stmt->bindValue(':expirationTime', $expirationTime);
        $stmt->bindValue(':createdAt', $createdAt);

        return $stmt->execute() && $stmt->rowCount() > 0;
    }

    /**
    * Retrieves cache data from the database.
    * 
    * @param string $cacheKey
    * @param string $namespace
    * @return mixed
     */
    public function retrieve(string $cacheKey, string $namespace = ''): mixed
    {
        $driver = $this->getDriver();
        $nowFunction = $this->getCurrentDateTime($driver);

        $stmt = $this->connection->prepare(
            "SELECT cacheData FROM {$this->table} 
            WHERE cacheKey = :cacheKey AND cacheNamespace = :namespace AND expirationTime > $nowFunction
            LIMIT 1"
        );
        $stmt->bindValue(':cacheKey', $cacheKey);
        $stmt->bindValue(':namespace', $namespace);
        $stmt->execute();

        $data = $stmt->fetch(PDO::FETCH_ASSOC);
        return (!empty($data)) ? $this->serialize($data['cacheData'], false) : null;
    }

    /**
     * Retrieves multiple cache items by their keys.
     * @param string $namespace
     * @return array
     */
    public function getAll(string $namespace = ''): array
    {
        $driver = $this->getDriver();
        $nowFunction = $this->getCurrentDateTime($driver);

        $stmt = $this->connection->prepare(
            "SELECT cacheKey, cacheData FROM {$this->table} 
            WHERE cacheNamespace = :namespace AND expirationTime > $nowFunction"
        );
        $stmt->bindValue(':namespace', $namespace);
        $stmt->execute();

        $results = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $results[$row['cacheKey']] = $this->serialize($row['cacheData'], false);
        }
        return $results;
    }

    /**
    * Get Update query based on the database driver.
    *
    * @return string
    */
    private function getUpdateQueryWithDriver(): string
    {
        $driver = $this->getDriver();
        if ($driver?->isMysqlFamily()) {
            return "UPDATE {$this->table} SET cacheData = :cacheData, cacheNamespace = :namespace WHERE cacheKey = :cacheKey LIMIT 1";
        }
        return "UPDATE {$this->table} SET cacheData = :cacheData, cacheNamespace = :namespace WHERE cacheKey = :cacheKey";
    }

    /**
    * Get Delete query based on the database driver.
    * 
    * @return string
    */
    private function getDeleteQueryWithDriver(): string
    {
        $driver = $this->getDriver();
        if ($driver?->isMysqlFamily()) {
            return "DELETE FROM {$this->table} WHERE cacheKey = :cacheKey AND cacheNamespace = :namespace LIMIT 1";
        }
        return "DELETE FROM {$this->table} WHERE cacheKey = :cacheKey AND cacheNamespace = :namespace";
    }

    /**
    * Updates an existing cache item in the database.
    * 
    * @param string $cacheKey
    * @param mixed  $cacheData
    * @param string $namespace
    * @return bool
    */
    public function update(string $cacheKey, mixed $cacheData, string $namespace = ''): bool
    {
        $query = $this->getUpdateQueryWithDriver();
        $stmt = $this->connection->prepare($query);
        $stmt->bindValue(':cacheData', $this->serialize($cacheData));
        $stmt->bindValue(':namespace', $namespace);
        $stmt->bindValue(':cacheKey', $cacheKey);
        $stmt->execute();

        return $stmt->rowCount() > 0;
    }

    /**
    * Clears a specific cache item from the database.
    * 
    * @param string $cacheKey
    * @param string $namespace
    * @return bool
    */
    public function clear(string $cacheKey, string $namespace = ''): bool
    {
        $query = $this->getDeleteQueryWithDriver();
        $stmt = $this->connection->prepare($query);
        $stmt->bindValue(':cacheKey', $cacheKey);
        $stmt->bindValue(':namespace', $namespace);
        $stmt->execute();

        return $stmt->rowCount() > 0;
    }

    /**
    * Gets the query to renew the expiration time of a cache item based on the database driver.
    *  
    * @return string
    */
    private function getRenewExpirationQueryWithDriver(): string
    {
        $driver = $this->getDriver();
        if ($driver === DatabaseDriver::SQLITE) {
            return "UPDATE {$this->table}
                    SET expirationTime = DATETIME(expirationTime, '+' || :ttl || ' seconds')
                    WHERE cacheKey = :cacheKey AND cacheNamespace = :namespace AND expirationTime > :currentTime";
        }
        return "UPDATE {$this->table}
                SET expirationTime = DATE_ADD(expirationTime, INTERVAL :ttl SECOND)
                WHERE cacheKey = :cacheKey AND cacheNamespace = :namespace AND expirationTime > :currentTime";
    }

    /**
    * Checks if a cache item is valid based on its key, namespace, and current time.
    * 
    * @param string $cacheKey
    * @param string $namespace
    * @param string $currentTime
    * @return bool
    */
    private function hasValidCache(string $cacheKey, string $namespace, string $currentTime): bool
    {
        $stmt = $this->connection->prepare(
            "SELECT 1 FROM {$this->table} 
            WHERE cacheKey = :cacheKey AND cacheNamespace = :namespace AND expirationTime > :currentTime
            LIMIT 1"
        );
        $stmt->bindValue(':cacheKey', $cacheKey);
        $stmt->bindValue(':namespace', $namespace);
        $stmt->bindValue(':currentTime', $currentTime);
        $stmt->execute();
        return $stmt->fetchColumn() !== false;
    }

    /**
    * Renews the expiration time of a cache item.
    * 
    * @param string $cacheKey
    * @param string|int $ttl
    * @param string $namespace
    * @return bool
    */
    public function renew(string $cacheKey, string|int $ttl, string $namespace = ''): bool
    {
        $currentTime = date('Y-m-d H:i:s');
        if (!$this->hasValidCache($cacheKey, $namespace, $currentTime)) {
            return false;
        }

        $query = $this->getRenewExpirationQueryWithDriver();
        $stmt = $this->connection->prepare($query);
        $stmt->bindValue(':ttl', (int) $ttl, PDO::PARAM_INT);
        $stmt->bindValue(':cacheKey', $cacheKey);
        $stmt->bindValue(':namespace', $namespace);
        $stmt->bindValue(':currentTime', $currentTime);
        $stmt->execute();

        return $stmt->rowCount() > 0;
    }

    /**
    * Flushes all cache items from the database.
    * 
    * @return bool
    */
    public function flush(): bool
    {
        return $this->connection->exec("DELETE FROM {$this->table}") !== false;
    }

    /**
     * Serializes or unserializes data based on the provided flag.
     *
     * @param mixed $data
     * @param bool $serialize
     * @return mixed
     */
    private function serialize(mixed $data, bool $serialize = true): mixed
    {
        return $serialize ? serialize($data) : unserialize($data);
    }

    /**
    * Gets the current date and time based on the database driver.
    * 
    * @param string $driver
    * @return string
    */
    private function getCurrentDateTime(?DatabaseDriver $driver): string
    {
        return ($driver === DatabaseDriver::SQLITE) ? "DATETIME('now', 'localtime')" : "NOW()";
    }

    /**
     * Resolve the current PDO driver as an enum instance when possible.
     */
    private function getDriver(): ?DatabaseDriver
    {
        $driverName = $this->connection->getAttribute(PDO::ATTR_DRIVER_NAME);
        return is_string($driverName) ? DatabaseDriver::tryFrom($driverName) : null;
    }
}
