<?php

declare(strict_types=1);

namespace Silviooosilva\CacheerPhp\Stores;

use PDO;
use Silviooosilva\CacheerPhp\Config\PipelineConfig;
use Silviooosilva\CacheerPhp\Contracts\AtomicStore;
use Silviooosilva\CacheerPhp\Contracts\BatchStore;
use Silviooosilva\CacheerPhp\Contracts\Clock;
use Silviooosilva\CacheerPhp\Contracts\FlushableScopeStore;
use Silviooosilva\CacheerPhp\Contracts\InspectableStore;
use Silviooosilva\CacheerPhp\Contracts\KeyEncoder;
use Silviooosilva\CacheerPhp\Contracts\Lock;
use Silviooosilva\CacheerPhp\Contracts\LockingStore;
use Silviooosilva\CacheerPhp\Contracts\PrunableStore;
use Silviooosilva\CacheerPhp\Contracts\Store;
use Silviooosilva\CacheerPhp\Contracts\TaggableStore;
use Silviooosilva\CacheerPhp\Contracts\TouchStore;
use Silviooosilva\CacheerPhp\Exceptions\StoreOperationFailedException;
use Silviooosilva\CacheerPhp\Kernel\CacheEntry;
use Silviooosilva\CacheerPhp\Kernel\Key;
use Silviooosilva\CacheerPhp\Kernel\Scope;
use Silviooosilva\CacheerPhp\Kernel\Ttl;
use Silviooosilva\CacheerPhp\Storage\EnvelopeCodec;
use Silviooosilva\CacheerPhp\Storage\KeyEncoder\HashingKeyEncoder;
use Silviooosilva\CacheerPhp\Stores\Support\DatabaseLock;
use Silviooosilva\CacheerPhp\Stores\Support\DatabaseStoreSchema;
use UnexpectedValueException;

/**
 * PDO-backed store for SQLite, MySQL/MariaDB, and PostgreSQL.
 *
 * The PDO connection and table name are injected; schema creation is an
 * explicit operation (DatabaseStoreSchema), never a constructor side effect.
 * Values flow through the v6 storage pipeline and are stored base64-encoded so
 * they are portable across engines. Writes upsert natively, batch writes run in
 * a transaction, and counters use a row-locked read-modify-write where the
 * engine supports it.
 */
final class DatabaseStore implements
    Store,
    BatchStore,
    TouchStore,
    PrunableStore,
    InspectableStore,
    FlushableScopeStore,
    TaggableStore,
    AtomicStore,
    LockingStore
{
    /**
     * @var string
     */
    private readonly string $driver;

    /**
     * @var EnvelopeCodec
     */
    private readonly EnvelopeCodec $codec;

    /**
     * @var KeyEncoder
     */
    private readonly KeyEncoder $keyEncoder;

    /**
     * @var Clock
     */
    private readonly Clock $clock;

    /**
     * @param PDO $pdo
     * @param string $table
     * @param ?EnvelopeCodec $codec
     * @param ?KeyEncoder $keyEncoder
     * @param ?Clock $clock
     * @param bool $migrateLegacyOnRead
     */
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $table = 'cacheer_store',
        ?EnvelopeCodec $codec = null,
        ?KeyEncoder $keyEncoder = null,
        ?Clock $clock = null,
        private readonly bool $migrateLegacyOnRead = false,
    ) {
        DatabaseStoreSchema::assertSafeTableName($this->table);
        $this->driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $this->codec = $codec ?? PipelineConfig::default()->codec();
        $this->keyEncoder = $keyEncoder ?? new HashingKeyEncoder();
        $this->clock = $clock ?? new \Silviooosilva\CacheerPhp\Support\SystemClock();
    }

    /**
     * @param Key $key
     * @return CacheEntry
     */
    public function get(Key $key): CacheEntry
    {
        $row = $this->selectRow($this->keyEncoder->encode($key));

        if ($row === null) {
            return CacheEntry::miss($key);
        }

        if ($this->isExpired($row['expires_at'])) {
            $this->delete($key);

            return CacheEntry::miss($key);
        }

        $value = $this->decode($row['value']);

        if ($this->migrateLegacyOnRead && $this->codec->isLegacyBlob((string) base64_decode($row['value'], true))) {
            $this->rewriteLegacy($this->keyEncoder->encode($key), $value);
        }

        return CacheEntry::hit($key, $value, (int) $row['created_at'], $this->nullableInt($row['expires_at']));
    }

    /**
     * Re-encode a v5 value in the v6 envelope in place, preserving its creation
     * and expiry timestamps.
     *
     * @param string $encodedKey
     * @param mixed $value
     */
    private function rewriteLegacy(string $encodedKey, mixed $value): void
    {
        $statement = $this->pdo->prepare("UPDATE {$this->table} SET value = :value WHERE cache_key = :key");
        $statement->execute([':value' => $this->encode($value), ':key' => $encodedKey]);
    }

    /**
     * @param Key $key
     * @param mixed $value
     * @param Ttl $ttl
     */
    public function set(Key $key, mixed $value, Ttl $ttl): void
    {
        $this->upsert($key, $value, $ttl->expiresAt($this->clock));
    }

    /**
     * @param Key $key
     * @return bool
     */
    public function delete(Key $key): bool
    {
        $statement = $this->pdo->prepare("DELETE FROM {$this->table} WHERE cache_key = :key");
        $statement->execute([':key' => $this->keyEncoder->encode($key)]);

        return $statement->rowCount() > 0;
    }

    public function clear(): void
    {
        $this->pdo->exec("DELETE FROM {$this->table}");
        $this->pdo->exec("DELETE FROM {$this->table}_tags");
    }

    /**
     * @param iterable<Key> $keys
     * @return list<CacheEntry>
     */
    public function getMany(iterable $keys): array
    {
        $entries = [];

        foreach ($keys as $key) {
            $entries[] = $this->get($key);
        }

        return $entries;
    }

    /**
     * @param iterable $entries
     * @param Ttl $ttl
     */
    public function setMany(iterable $entries, Ttl $ttl): void
    {
        $expiresAt = $ttl->expiresAt($this->clock);

        $this->transaction(function () use ($entries, $expiresAt): void {
            foreach ($entries as $entry) {
                $this->upsert($entry['key'], $entry['value'], $expiresAt);
            }
        });
    }

    /**
     * @param iterable<Key> $keys
     * @return bool
     */
    public function deleteMany(iterable $keys): bool
    {
        $deleted = true;

        $this->transaction(function () use ($keys, &$deleted): void {
            foreach ($keys as $key) {
                $deleted = $this->delete($key) && $deleted;
            }
        });

        return $deleted;
    }

    /**
     * @param Key $key
     * @param Ttl $ttl
     * @return bool
     */
    public function touch(Key $key, Ttl $ttl): bool
    {
        $statement = $this->pdo->prepare(
            "UPDATE {$this->table} SET expires_at = :expires WHERE cache_key = :key",
        );
        $statement->execute([
            ':expires' => $ttl->expiresAt($this->clock),
            ':key'     => $this->keyEncoder->encode($key),
        ]);

        return $statement->rowCount() > 0 && $this->get($key)->isHit();
    }

    /**
     * @return int
     */
    public function prune(): int
    {
        $statement = $this->pdo->prepare(
            "DELETE FROM {$this->table} WHERE expires_at IS NOT NULL AND expires_at <= :now",
        );
        $statement->execute([':now' => $this->clock->now()]);

        return $statement->rowCount();
    }

    /**
     * @param ?Scope $scope
     * @return iterable<CacheEntry>
     */
    public function entries(?Scope $scope = null): iterable
    {
        $scope ??= Scope::root();
        [$where, $params] = $this->scopeClause($scope);

        $sql = "SELECT scope, key_value, value, created_at, expires_at FROM {$this->table}";
        if ($where !== '') {
            $sql .= ' WHERE ' . $where;
        }

        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);

        while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
            /** @var array{scope: string, key_value: string, value: string, created_at: int|string, expires_at: int|string|null} $row */
            if ($this->isExpired($row['expires_at'])) {
                continue;
            }

            $key = Key::named($row['key_value'])->within($this->scopeFromString($row['scope']));
            yield CacheEntry::hit($key, $this->decode($row['value']), (int) $row['created_at'], $this->nullableInt($row['expires_at']));
        }
    }

    /**
     * @param Scope $scope
     */
    public function clearScope(Scope $scope): void
    {
        if ($scope->isRoot()) {
            $this->clear();

            return;
        }

        [$where, $params] = $this->scopeClause($scope);
        $statement = $this->pdo->prepare("DELETE FROM {$this->table} WHERE " . $where);
        $statement->execute($params);
    }

    /**
     * @param Key $key
     * @param string ...$tags
     */
    public function tag(Key $key, string ...$tags): void
    {
        $encoded = $this->keyEncoder->encode($key);
        $insert = $this->pdo->prepare("INSERT INTO {$this->table}_tags (tag, cache_key) VALUES (:tag, :key)");

        foreach ($tags as $tag) {
            $insert->execute([':tag' => $tag, ':key' => $encoded]);
        }
    }

    /**
     * @param string $tag
     * @return int
     */
    public function clearTag(string $tag): int
    {
        return $this->transaction(function () use ($tag): int {
            $select = $this->pdo->prepare("SELECT DISTINCT cache_key FROM {$this->table}_tags WHERE tag = :tag");
            $select->execute([':tag' => $tag]);
            $keys = $select->fetchAll(PDO::FETCH_COLUMN);

            $removed = 0;
            $delete = $this->pdo->prepare("DELETE FROM {$this->table} WHERE cache_key = :key");
            foreach ($keys as $cacheKey) {
                $delete->execute([':key' => $cacheKey]);
                $removed += $delete->rowCount();
            }

            $this->pdo->prepare("DELETE FROM {$this->table}_tags WHERE tag = :tag")->execute([':tag' => $tag]);

            return $removed;
        });
    }

    /**
     * @param Key $key
     * @param int $amount
     * @param ?int $initial
     * @param ?Ttl $ttl
     * @return int
     */
    public function increment(Key $key, int $amount = 1, ?int $initial = null, ?Ttl $ttl = null): int
    {
        return $this->transaction(function () use ($key, $amount, $initial, $ttl): int {
            $row = $this->selectRow($this->keyEncoder->encode($key), $this->supportsRowLock());

            if ($row !== null && !$this->isExpired($row['expires_at'])) {
                $current = $this->decode($row['value']);
                if (!is_int($current)) {
                    throw new StoreOperationFailedException(
                        'increment',
                        $key,
                        new UnexpectedValueException('Cannot increment a non-integer cache value.'),
                    );
                }
                $next = $current + $amount;
                $expiresAt = $ttl?->expiresAt($this->clock) ?? $this->nullableInt($row['expires_at']);
            } else {
                $next = ($initial ?? 0) + $amount;
                $expiresAt = $ttl?->expiresAt($this->clock);
            }

            $this->upsert($key, $next, $expiresAt);

            return $next;
        });
    }

    /**
     * @param Key $key
     * @param mixed $expected
     * @param mixed $value
     * @param ?Ttl $ttl
     * @return bool
     */
    public function compareAndSwap(Key $key, mixed $expected, mixed $value, ?Ttl $ttl = null): bool
    {
        return $this->transaction(function () use ($key, $expected, $value, $ttl): bool {
            $row = $this->selectRow($this->keyEncoder->encode($key), $this->supportsRowLock());

            if ($row === null || $this->isExpired($row['expires_at']) || $this->decode($row['value']) !== $expected) {
                return false;
            }

            $this->upsert($key, $value, $ttl?->expiresAt($this->clock) ?? $this->nullableInt($row['expires_at']));

            return true;
        });
    }

    /**
     * @param string $name
     * @param Ttl $ttl
     * @return Lock
     */
    public function lock(string $name, Ttl $ttl): Lock
    {
        return new DatabaseLock($this->pdo, $this->table, $this->clock, $name, $ttl);
    }

    /**
     * @param string $cacheKey
     * @param bool $forUpdate
     * @return array{scope: string, key_value: string, value: string, created_at: int|string, expires_at: int|string|null}|null
     */
    private function selectRow(string $cacheKey, bool $forUpdate = false): ?array
    {
        $sql = "SELECT scope, key_value, value, created_at, expires_at FROM {$this->table} WHERE cache_key = :key";
        if ($forUpdate) {
            $sql .= ' FOR UPDATE';
        }

        $statement = $this->pdo->prepare($sql);
        $statement->execute([':key' => $cacheKey]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        /** @var array{scope: string, key_value: string, value: string, created_at: int|string, expires_at: int|string|null}|false $row */
        return $row === false ? null : $row;
    }

    /**
     * @param Key $key
     * @param mixed $value
     * @param ?int $expiresAt
     */
    private function upsert(Key $key, mixed $value, ?int $expiresAt): void
    {
        $params = [
            ':key'     => $this->keyEncoder->encode($key),
            ':scope'   => (string) $key->scope(),
            ':kv'      => $key->value(),
            ':value'   => $this->encode($value),
            ':created' => $this->clock->now(),
            ':expires' => $expiresAt,
        ];

        $this->pdo->prepare($this->upsertSql())->execute($params);
    }

    /**
     * @return string
     */
    private function upsertSql(): string
    {
        $columns = '(cache_key, scope, key_value, value, created_at, expires_at)';
        $values = '(:key, :scope, :kv, :value, :created, :expires)';

        if ($this->driver === 'mysql') {
            return "INSERT INTO {$this->table} {$columns} VALUES {$values}
                ON DUPLICATE KEY UPDATE scope = VALUES(scope), key_value = VALUES(key_value),
                value = VALUES(value), created_at = VALUES(created_at), expires_at = VALUES(expires_at)";
        }

        return "INSERT INTO {$this->table} {$columns} VALUES {$values}
            ON CONFLICT (cache_key) DO UPDATE SET scope = excluded.scope, key_value = excluded.key_value,
            value = excluded.value, created_at = excluded.created_at, expires_at = excluded.expires_at";
    }

    /**
     * @param Scope $scope
     * @return array{0: string, 1: array<string, int|string>}
     */
    private function scopeClause(Scope $scope): array
    {
        if ($scope->isRoot()) {
            return ['', []];
        }

        return [
            '(scope = :scope OR scope LIKE :prefix)',
            [':scope' => (string) $scope, ':prefix' => (string) $scope . '/%'],
        ];
    }

    /**
     * @param string $scope
     * @return Scope
     */
    private function scopeFromString(string $scope): Scope
    {
        if ($scope === '') {
            return Scope::root();
        }

        return Scope::fromSegments(explode('/', $scope));
    }

    /**
     * @param mixed $value
     * @return string
     */
    private function encode(mixed $value): string
    {
        return base64_encode($this->codec->encode($value));
    }

    /**
     * @param string $value
     * @return mixed
     */
    private function decode(string $value): mixed
    {
        return $this->codec->decode((string) base64_decode($value, true));
    }

    /**
     * @param string|int|null $expiresAt
     * @return bool
     */
    private function isExpired(int|string|null $expiresAt): bool
    {
        return $expiresAt !== null && (int) $expiresAt <= $this->clock->now();
    }

    /**
     * @param string|int|null $value
     * @return ?int
     */
    private function nullableInt(int|string|null $value): ?int
    {
        return $value === null ? null : (int) $value;
    }

    /**
     * @return bool
     */
    private function supportsRowLock(): bool
    {
        return $this->driver === 'mysql' || $this->driver === 'pgsql';
    }

    /**
     * @template T
     * @param callable(): T $operation
     * @return T
     */
    private function transaction(callable $operation): mixed
    {
        $owns = !$this->pdo->inTransaction();
        if ($owns) {
            $this->pdo->beginTransaction();
        }

        try {
            $result = $operation();
            if ($owns) {
                $this->pdo->commit();
            }

            return $result;
        } catch (\Throwable $exception) {
            if ($owns && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $exception;
        }
    }
}
