<?php

declare(strict_types=1);

namespace Silviooosilva\CacheerPhp\Stores\Support;

use Redis;
use Silviooosilva\CacheerPhp\Contracts\RedisConnection;

/**
 * RedisConnection adapter for the native phpredis extension (\Redis).
 *
 * Behaves identically to {@see PredisConnection}; only the client API differs.
 * Requires ext-redis, which is verified in CI rather than the service-free
 * suites, so this file is excluded from static analysis where the extension is
 * absent.
 */
final class PhpRedisConnection implements RedisConnection
{
    /**
     * @param Redis $client
     */
    public function __construct(private readonly Redis $client)
    {
    }

    /**
     * @param string $key
     * @return ?string
     */
    public function get(string $key): ?string
    {
        $value = $this->client->get($key);

        return $value === false ? null : (string) $value;
    }

    /**
     * @param string $key
     * @param string $value
     * @param ?int $ttlMillis
     */
    public function set(string $key, string $value, ?int $ttlMillis): void
    {
        if ($ttlMillis === null) {
            $this->client->set($key, $value);

            return;
        }

        $this->client->set($key, $value, ['px' => $ttlMillis]);
    }

    /**
     * @param string $key
     * @param string $value
     * @param ?int $ttlMillis
     * @return bool
     */
    public function setIfAbsent(string $key, string $value, ?int $ttlMillis): bool
    {
        $options = ['nx'];
        if ($ttlMillis !== null) {
            $options = ['nx', 'px' => $ttlMillis];
        }

        return $this->client->set($key, $value, $options) !== false;
    }

    /**
     * @param list<string> $keys
     * @return int
     */
    public function delete(array $keys): int
    {
        if ($keys === []) {
            return 0;
        }

        return (int) $this->client->del($keys);
    }

    /**
     * @param list<string> $keys
     * @return list<string|null>
     */
    public function getMany(array $keys): array
    {
        if ($keys === []) {
            return [];
        }

        $values = $this->client->mget($keys);

        return array_map(static fn ($value): ?string => $value === false ? null : (string) $value, $values);
    }

    /**
     * @param string $match
     * @return iterable<string>
     */
    public function scan(string $match): iterable
    {
        $iterator = null;

        while (true) {
            $keys = $this->client->scan($iterator, $match, 200);
            if ($keys === false) {
                break;
            }

            foreach ($keys as $key) {
                yield (string) $key;
            }

            if ($iterator === 0) {
                break;
            }
        }
    }

    /**
     * @param string $set
     * @param string $member
     */
    public function sAdd(string $set, string $member): void
    {
        $this->client->sAdd($set, $member);
    }

    /**
     * @param string $set
     * @return list<string>
     */
    public function sMembers(string $set): array
    {
        return array_map(static fn ($value): string => (string) $value, $this->client->sMembers($set));
    }

    /**
     * @param string $script
     * @param list<string> $keys
     * @param list<string> $args
     * @return mixed
     */
    public function eval(string $script, array $keys, array $args): mixed
    {
        return $this->client->eval($script, [...$keys, ...$args], count($keys));
    }
}
