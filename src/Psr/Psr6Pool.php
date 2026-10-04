<?php

declare(strict_types=1);

namespace Silviooosilva\CacheerPhp\Psr;

use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Silviooosilva\CacheerPhp\Contracts\Cache;
use Silviooosilva\CacheerPhp\Contracts\Clock;
use Silviooosilva\CacheerPhp\Exceptions\CacheException;
use Silviooosilva\CacheerPhp\Exceptions\CacheInvalidArgumentException;
use Silviooosilva\CacheerPhp\Exceptions\InvalidKeyException;
use Silviooosilva\CacheerPhp\Exceptions\InvalidTtlException;
use Silviooosilva\CacheerPhp\Kernel\Key;
use Silviooosilva\CacheerPhp\Support\SystemClock;

/**
 * PSR-6 pool over the v6 kernel, with deferred saves and commit.
 *
 * getItem() reflects both persisted values and items queued with saveDeferred()
 * in this pool. Absolute PSR-6 expirations are converted to the kernel's TTL
 * against the injected clock; an item already past its expiration is treated as
 * a miss / delete.
 */
final class Psr6Pool implements CacheItemPoolInterface
{
    private const RESERVED = '{}()/\\@:';

    /**
     * Items queued by saveDeferred(), copied and with their expiry pinned to an
     * absolute time when queued. null marks one already expired when queued,
     * which commit() deletes.
     *
     * @var array<string, ?Psr6Item>
     */
    private array $deferred = [];

    /**
     * @param Cache $cache
     * @param Clock $clock
     */
    public function __construct(
        private readonly Cache $cache,
        private readonly Clock $clock = new SystemClock(),
    ) {
    }

    /**
     * @param string $key
     * @return CacheItemInterface
     */
    public function getItem(string $key): CacheItemInterface
    {
        $this->validateKey($key);

        if (array_key_exists($key, $this->deferred)) {
            return $this->readDeferred($key);
        }

        $entry = $this->cache->entry($key);

        return $entry->isHit()
            ? Psr6Item::hit($key, $entry->value(), $entry->expiresAt())
            : Psr6Item::miss($key);
    }

    /**
     * @param array<mixed> $keys
     * @return iterable<string, CacheItemInterface>
     */
    public function getItems(array $keys = []): iterable
    {
        $items = [];

        foreach ($keys as $key) {
            $items[$this->validateKey($key)] = $this->getItem($key);
        }

        return $items;
    }

    /**
     * @param string $key
     * @return bool
     */
    public function hasItem(string $key): bool
    {
        $this->validateKey($key);

        if (array_key_exists($key, $this->deferred)) {
            return $this->readDeferred($key)->isHit();
        }

        return $this->cache->has($key);
    }

    /**
     * @return bool
     */
    public function clear(): bool
    {
        $this->deferred = [];

        return $this->attempt(function (): void {
            $this->cache->clear();
        });
    }

    /**
     * @param string $key
     * @return bool
     */
    public function deleteItem(string $key): bool
    {
        $this->validateKey($key);
        unset($this->deferred[$key]);

        return $this->attempt(function () use ($key): void {
            $this->cache->delete($key);
        });
    }

    /**
     * @param array<mixed> $keys
     * @return bool
     */
    public function deleteItems(array $keys): bool
    {
        $validated = array_map($this->validateKey(...), $keys);

        $ok = true;
        foreach ($validated as $key) {
            $ok = $this->deleteItem($key) && $ok;
        }

        return $ok;
    }

    /**
     * @param CacheItemInterface $item
     * @return bool
     */
    public function save(CacheItemInterface $item): bool
    {
        $key = $this->validateKey($item->getKey());

        if (!$item instanceof Psr6Item) {
            return $this->attempt(function () use ($key, $item): void {
                $this->cache->set($key, $item->get(), null);
            });
        }

        return $this->attempt(function () use ($key, $item): void {
            $ttl = $item->resolveTtl($this->clock);

            if ($ttl === false) {
                $this->cache->delete($key);

                return;
            }

            $this->cache->set($key, $item->rawValue(), $ttl);
        });
    }

    /**
     * @param CacheItemInterface $item
     * @return bool
     */
    public function saveDeferred(CacheItemInterface $item): bool
    {
        $key = $this->validateKey($item->getKey());

        if (!$item instanceof Psr6Item) {
            $this->deferred[$key] = Psr6Item::hit($key, $item->get(), null);

            return true;
        }

        // Copy the item so later changes to it do not alter what is queued, and
        // pin a relative expiry now: its lifetime starts here, not at commit().
        $ttl = $this->guardArguments(fn (): mixed => $item->resolveTtl($this->clock));
        $this->deferred[$key] = $ttl === false
            ? null
            : Psr6Item::hit($key, $item->rawValue(), $ttl === null ? null : $this->guardArguments(fn (): ?int => $ttl->expiresAt($this->clock)));

        return true;
    }

    /**
     * @return bool
     */
    public function commit(): bool
    {
        $ok = true;

        foreach ($this->deferred as $key => $item) {
            $ok = ($item === null
                ? $this->attempt(function () use ($key): void {
                    $this->cache->delete($key);
                })
                : $this->save($item)) && $ok;
        }

        $this->deferred = [];

        return $ok;
    }

    /**
     * A copy of a queued item: a hit until its pinned expiry, then a miss.
     *
     * @param string $key
     * @return Psr6Item
     */
    private function readDeferred(string $key): Psr6Item
    {
        $item = $this->deferred[$key];

        if ($item === null || $item->resolveTtl($this->clock) === false) {
            return Psr6Item::miss($key);
        }

        return clone $item;
    }

    /**
     * Runs a write and reports a store failure as false, as PSR-6 requires;
     * invalid input still surfaces as the PSR InvalidArgumentException.
     *
     * @param callable(): void $operation
     * @return bool
     */
    private function attempt(callable $operation): bool
    {
        try {
            $this->guardArguments($operation);

            return true;
        } catch (CacheInvalidArgumentException $exception) {
            throw $exception;
        } catch (CacheException) {
            return false;
        }
    }

    /**
     * @template T
     * @param callable(): T $operation
     * @return T
     */
    private function guardArguments(callable $operation): mixed
    {
        try {
            return $operation();
        } catch (InvalidKeyException|InvalidTtlException $exception) {
            throw CacheInvalidArgumentException::create($exception->getMessage());
        }
    }

    /**
     * Applies the PSR-6 key rules and the native ones, so every invalid key is
     * reported as the PSR InvalidArgumentException.
     *
     * @param mixed $key
     * @return string
     */
    private function validateKey(mixed $key): string
    {
        if (!is_string($key)) {
            throw CacheInvalidArgumentException::create(sprintf('Cache keys must be strings, %s given.', get_debug_type($key)));
        }

        if ($key === '') {
            throw CacheInvalidArgumentException::create('Cache key must not be empty.');
        }

        if (strpbrk($key, self::RESERVED) !== false) {
            throw CacheInvalidArgumentException::create(
                sprintf('Cache key "%s" contains reserved characters (%s).', $key, self::RESERVED),
            );
        }

        $this->guardArguments(static fn (): Key => Key::named($key));

        return $key;
    }
}
