<?php

declare(strict_types=1);

namespace Silviooosilva\CacheerPhp\Psr;

use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Silviooosilva\CacheerPhp\Contracts\Cache;
use Silviooosilva\CacheerPhp\Contracts\Clock;
use Silviooosilva\CacheerPhp\Exceptions\CacheInvalidArgumentException;
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
     * @var array<string, Psr6Item>
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

        if (isset($this->deferred[$key])) {
            return $this->deferred[$key];
        }

        $entry = $this->cache->entry($key);

        return $entry->isHit()
            ? Psr6Item::hit($key, $entry->value(), $entry->expiresAt())
            : Psr6Item::miss($key);
    }

    /**
     * @param array<string> $keys
     * @return iterable<string, CacheItemInterface>
     */
    public function getItems(array $keys = []): iterable
    {
        $items = [];
        foreach ($keys as $key) {
            $items[$key] = $this->getItem($key);
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

        return isset($this->deferred[$key]) || $this->cache->has($key);
    }

    /**
     * @return bool
     */
    public function clear(): bool
    {
        $this->deferred = [];
        $this->cache->clear();

        return true;
    }

    /**
     * @param string $key
     * @return bool
     */
    public function deleteItem(string $key): bool
    {
        $this->validateKey($key);
        unset($this->deferred[$key]);
        $this->cache->delete($key);

        return true;
    }

    /**
     * @param array<string> $keys
     * @return bool
     */
    public function deleteItems(array $keys): bool
    {
        foreach ($keys as $key) {
            $this->deleteItem($key);
        }

        return true;
    }

    /**
     * @param CacheItemInterface $item
     * @return bool
     */
    public function save(CacheItemInterface $item): bool
    {
        if (!$item instanceof Psr6Item) {
            $this->cache->set($item->getKey(), $item->get(), null);

            return true;
        }

        $ttl = $item->resolveTtl($this->clock);
        if ($ttl === false) {
            $this->cache->delete($item->getKey());

            return true;
        }

        $this->cache->set($item->getKey(), $item->rawValue(), $ttl);

        return true;
    }

    /**
     * @param CacheItemInterface $item
     * @return bool
     */
    public function saveDeferred(CacheItemInterface $item): bool
    {
        $this->deferred[$item->getKey()] = $item instanceof Psr6Item
            ? $item
            : Psr6Item::hit($item->getKey(), $item->get(), null);

        return true;
    }

    /**
     * @return bool
     */
    public function commit(): bool
    {
        $ok = true;
        foreach ($this->deferred as $item) {
            $ok = $this->save($item) && $ok;
        }
        $this->deferred = [];

        return $ok;
    }

    /**
     * @param string $key
     */
    private function validateKey(string $key): void
    {
        if ($key === '') {
            throw CacheInvalidArgumentException::create('Cache key must not be empty.');
        }

        if (strpbrk($key, self::RESERVED) !== false) {
            throw CacheInvalidArgumentException::create(
                sprintf('Cache key "%s" contains reserved characters (%s).', $key, self::RESERVED),
            );
        }
    }
}
