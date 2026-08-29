<?php

declare(strict_types=1);

namespace Silviooosilva\CacheerPhp\Stores\Support;

use Predis\ClientInterface;
use Silviooosilva\CacheerPhp\Contracts\RedisConnection;

/**
 * RedisConnection adapter for the Predis client.
 */
final class PredisConnection implements RedisConnection
{
    /**
     * @param ClientInterface $client
     */
    public function __construct(private readonly ClientInterface $client)
    {
    }

    /**
     * @param string $key
     * @return ?string
     */
    public function get(string $key): ?string
    {
        /** @var string|null $value */
        $value = $this->client->get($key);

        return $value;
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

        $this->client->set($key, $value, 'PX', $ttlMillis);
    }

    /**
     * @param string $key
     * @param string $value
     * @param ?int $ttlMillis
     * @return bool
     */
    public function setIfAbsent(string $key, string $value, ?int $ttlMillis): bool
    {
        $result = $ttlMillis === null
            ? $this->client->set($key, $value, 'NX')
            : $this->client->set($key, $value, 'PX', $ttlMillis, 'NX');

        return $result !== null;
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

        /** @var list<string|null> $values */
        $values = $this->client->mget($keys);

        return $values;
    }

    /**
     * @param string $match
     * @return iterable<string>
     */
    public function scan(string $match): iterable
    {
        $cursor = '0';

        do {
            /** @var array{0: string, 1: list<string>} $result */
            $result = $this->client->scan($cursor, ['MATCH' => $match, 'COUNT' => 200]);
            [$cursor, $keys] = $result;

            foreach ($keys as $key) {
                yield $key;
            }
        } while ($cursor !== '0');
    }

    /**
     * @param string $set
     * @param string $member
     */
    public function sAdd(string $set, string $member): void
    {
        $this->client->sadd($set, [$member]);
    }

    /**
     * @param string $set
     * @return list<string>
     */
    public function sMembers(string $set): array
    {
        /** @var list<string> $members */
        $members = $this->client->smembers($set);

        return $members;
    }

    /**
     * @param string $script
     * @param list<string> $keys
     * @param list<string> $args
     * @return mixed
     */
    public function eval(string $script, array $keys, array $args): mixed
    {
        return $this->client->eval($script, count($keys), ...$keys, ...$args);
    }
}
