<?php

declare(strict_types=1);

namespace Silviooosilva\CacheerPhp\Core;

use DateInterval;
use Silviooosilva\CacheerPhp\Contracts\BatchStore;
use Silviooosilva\CacheerPhp\Contracts\FlushableScopeStore;
use Silviooosilva\CacheerPhp\Contracts\Store;
use Silviooosilva\CacheerPhp\Exceptions\CacheException;
use Silviooosilva\CacheerPhp\Exceptions\InvalidKeyException;
use Silviooosilva\CacheerPhp\Exceptions\StoreOperationFailedException;
use Silviooosilva\CacheerPhp\Exceptions\UnsupportedCapabilityException;
use Silviooosilva\CacheerPhp\Kernel\CacheEntry;
use Silviooosilva\CacheerPhp\Kernel\Key;
use Silviooosilva\CacheerPhp\Kernel\Scope;
use Silviooosilva\CacheerPhp\Kernel\Ttl;
use Throwable;
use UnexpectedValueException;

/**
 * Shared instance-only behavior for Cache and ScopedCache.
 *
 * @internal
 */
final readonly class CacheOperations
{
    public function __construct(
        private Store $store,
        private Scope $scope,
    ) {
    }

    public function entry(string|Key $key): CacheEntry
    {
        $key = $this->key($key);

        return $this->run('get', $key, fn (): CacheEntry => $this->store->get($key));
    }

    public function get(string|Key $key, mixed $default = null): mixed
    {
        return $this->entry($key)->valueOr($default);
    }

    public function set(
        string|Key $key,
        mixed $value,
        Ttl|DateInterval|int|string|null $ttl = null,
    ): void {
        $key = $this->key($key);
        $ttl = Ttl::from($ttl);

        $this->run('set', $key, function () use ($key, $value, $ttl): void {
            $this->store->set($key, $value, $ttl);
        });
    }

    public function delete(string|Key $key): bool
    {
        $key = $this->key($key);

        return $this->run('delete', $key, fn (): bool => $this->store->delete($key));
    }

    public function clear(): void
    {
        if ($this->scope->isRoot()) {
            $this->run('clear', null, function (): void {
                $this->store->clear();
            });

            return;
        }

        if (!$this->store instanceof FlushableScopeStore) {
            throw UnsupportedCapabilityException::for(FlushableScopeStore::class, 'clear');
        }

        $this->run('clearScope', null, function (): void {
            $this->store->clearScope($this->scope);
        });
    }

    public function has(string|Key $key): bool
    {
        return $this->entry($key)->isHit();
    }

    public function remember(
        string|Key $key,
        Ttl|DateInterval|int|string|null $ttl,
        callable $callback,
    ): mixed {
        $entry = $this->entry($key);
        if ($entry->isHit()) {
            return $entry->value();
        }

        $value = $callback();
        $this->set($key, $value, $ttl);

        return $value;
    }

    /**
     * @param iterable<string|Key> $keys
     * @return array<string, mixed>
     */
    public function many(iterable $keys, mixed $default = null): array
    {
        $normalized = [];

        foreach ($keys as $key) {
            $normalized[] = $this->key($key);
        }

        if ($this->store instanceof BatchStore) {
            $entries = $this->run(
                'getMany',
                null,
                fn (): array => $this->store->getMany($normalized),
            );

            if (count($entries) !== count($normalized)) {
                throw new StoreOperationFailedException(
                    'getMany',
                    null,
                    new UnexpectedValueException('A BatchStore must return one entry for every requested key.'),
                );
            }
        } else {
            $entries = array_map(
                fn (Key $key): CacheEntry => $this->run(
                    'get',
                    $key,
                    fn (): CacheEntry => $this->store->get($key),
                ),
                $normalized,
            );
        }

        $values = [];
        foreach ($entries as $index => $entry) {
            $values[$normalized[$index]->value()] = $entry->valueOr($default);
        }

        return $values;
    }

    /**
     * @param iterable<array-key, mixed> $values
     */
    public function setMany(
        iterable $values,
        Ttl|DateInterval|int|string|null $ttl = null,
    ): void {
        $entries = [];

        foreach ($values as $key => $value) {
            if (!is_string($key)) {
                throw new InvalidKeyException('setMany() requires string keys.');
            }

            $entries[] = ['key' => $this->key($key), 'value' => $value];
        }

        $ttl = Ttl::from($ttl);

        if ($this->store instanceof BatchStore) {
            $this->run('setMany', null, function () use ($entries, $ttl): void {
                $this->store->setMany($entries, $ttl);
            });

            return;
        }

        foreach ($entries as $entry) {
            $this->run('set', $entry['key'], function () use ($entry, $ttl): void {
                $this->store->set($entry['key'], $entry['value'], $ttl);
            });
        }
    }

    /**
     * @param iterable<string|Key> $keys
     */
    public function deleteMany(iterable $keys): bool
    {
        $normalized = [];

        foreach ($keys as $key) {
            $normalized[] = $this->key($key);
        }

        if ($this->store instanceof BatchStore) {
            return $this->run(
                'deleteMany',
                null,
                fn (): bool => $this->store->deleteMany($normalized),
            );
        }

        $deleted = true;
        foreach ($normalized as $key) {
            $deleted = $this->run(
                'delete',
                $key,
                fn (): bool => $this->store->delete($key),
            ) && $deleted;
        }

        return $deleted;
    }

    public function nestedScope(string|Scope $scope): Scope
    {
        $scope = is_string($scope) ? Scope::named($scope) : $scope;

        return $this->scope->append($scope);
    }

    private function key(string|Key $key): Key
    {
        $key = is_string($key) ? Key::named($key) : $key;

        return $key->within($this->scope);
    }

    /**
     * @template T
     * @param callable(): T $operation
     * @return T
     */
    private function run(string $name, ?Key $key, callable $operation): mixed
    {
        try {
            return $operation();
        } catch (CacheException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new StoreOperationFailedException($name, $key, $exception);
        }
    }
}
