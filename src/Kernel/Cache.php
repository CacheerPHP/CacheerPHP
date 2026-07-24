<?php

declare(strict_types=1);

namespace Silviooosilva\CacheerPhp\Kernel;

use DateInterval;
use Silviooosilva\CacheerPhp\Contracts\Store;
use Silviooosilva\CacheerPhp\Core\CacheOperations;

/**
 * Explicit, instance-first v6 cache API.
 */
final readonly class Cache
{
    private CacheOperations $operations;

    public function __construct(private Store $store)
    {
        $this->operations = new CacheOperations($store, Scope::root());
    }

    public function entry(string|Key $key): CacheEntry
    {
        return $this->operations->entry($key);
    }

    public function get(string|Key $key, mixed $default = null): mixed
    {
        return $this->operations->get($key, $default);
    }

    public function set(
        string|Key $key,
        mixed $value,
        Ttl|DateInterval|int|string|null $ttl = null,
    ): void {
        $this->operations->set($key, $value, $ttl);
    }

    public function delete(string|Key $key): bool
    {
        return $this->operations->delete($key);
    }

    public function clear(): void
    {
        $this->operations->clear();
    }

    public function has(string|Key $key): bool
    {
        return $this->operations->has($key);
    }

    public function remember(
        string|Key $key,
        Ttl|DateInterval|int|string|null $ttl,
        callable $callback,
    ): mixed {
        return $this->operations->remember($key, $ttl, $callback);
    }

    /**
     * @param iterable<string|Key> $keys
     * @return array<string, mixed>
     */
    public function many(iterable $keys, mixed $default = null): array
    {
        return $this->operations->many($keys, $default);
    }

    /**
     * @param iterable<array-key, mixed> $values
     */
    public function setMany(
        iterable $values,
        Ttl|DateInterval|int|string|null $ttl = null,
    ): void {
        $this->operations->setMany($values, $ttl);
    }

    /**
     * @param iterable<string|Key> $keys
     */
    public function deleteMany(iterable $keys): bool
    {
        return $this->operations->deleteMany($keys);
    }

    public function scope(string|Scope $scope): ScopedCache
    {
        return new ScopedCache(
            $this->store,
            $this->operations->nestedScope($scope),
        );
    }
}
