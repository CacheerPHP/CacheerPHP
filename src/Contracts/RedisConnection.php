<?php

declare(strict_types=1);

namespace Silviooosilva\CacheerPhp\Contracts;

/**
 * The minimal Redis command surface the RedisStore depends on.
 *
 * RedisStore never creates a client of its own; it is handed one of these
 * adapters (Predis or phpredis ship built in, custom clients can implement it).
 * All methods operate only on the keys passed to them, so the store can confine
 * every scan and delete to its own keyspace.
 */
interface RedisConnection
{
    public function get(string $key): ?string;

    /**
     * @param int|null $ttlMillis Expiry in milliseconds, or null for no expiry.
     */
    public function set(string $key, string $value, ?int $ttlMillis): void;

    /**
     * SET ... NX: store only if the key is absent. Returns whether it was stored.
     *
     * @param int|null $ttlMillis Expiry in milliseconds, or null for no expiry.
     */
    public function setIfAbsent(string $key, string $value, ?int $ttlMillis): bool;

    /**
     * @param list<string> $keys
     * @return int Number of keys removed.
     */
    public function delete(array $keys): int;

    /**
     * @param list<string> $keys
     * @return list<string|null> Values in the same order, null for missing keys.
     */
    public function getMany(array $keys): array;

    /**
     * Iterate keys matching a glob pattern via SCAN (never the blocking KEYS).
     *
     * @return iterable<string>
     */
    public function scan(string $match): iterable;

    public function sAdd(string $set, string $member): void;

    /**
     * @return list<string>
     */
    public function sMembers(string $set): array;

    /**
     * @param list<string> $keys
     * @param list<string> $args
     */
    public function eval(string $script, array $keys, array $args): mixed;
}
