<?php

declare(strict_types=1);

namespace Silviooosilva\CacheerPhp\Stores;

use Silviooosilva\CacheerPhp\Contracts\BatchStore;
use Silviooosilva\CacheerPhp\Contracts\Clock;
use Silviooosilva\CacheerPhp\Contracts\FlushableScopeStore;
use Silviooosilva\CacheerPhp\Contracts\InspectableStore;
use Silviooosilva\CacheerPhp\Contracts\PrunableStore;
use Silviooosilva\CacheerPhp\Contracts\Store;
use Silviooosilva\CacheerPhp\Contracts\TouchStore;
use Silviooosilva\CacheerPhp\Kernel\CacheEntry;
use Silviooosilva\CacheerPhp\Kernel\Key;
use Silviooosilva\CacheerPhp\Kernel\Scope;
use Silviooosilva\CacheerPhp\Kernel\Ttl;
use Silviooosilva\CacheerPhp\Support\SystemClock;

/**
 * Service-free executable reference for the v6 store contracts.
 */
final class ArrayStore implements
    Store,
    BatchStore,
    TouchStore,
    PrunableStore,
    InspectableStore,
    FlushableScopeStore
{
    /**
     * @var array<string, CacheEntry>
     */
    private array $items = [];

    public function __construct(private readonly Clock $clock = new SystemClock())
    {
    }

    public function get(Key $key): CacheEntry
    {
        $identity = $key->identity();
        $entry = $this->items[$identity] ?? null;

        if ($entry === null) {
            return CacheEntry::miss($key);
        }

        if ($entry->isExpired($this->clock)) {
            unset($this->items[$identity]);

            return CacheEntry::miss($key);
        }

        return $entry;
    }

    public function set(Key $key, mixed $value, Ttl $ttl): void
    {
        $createdAt = $this->clock->now();
        $this->items[$key->identity()] = CacheEntry::hit(
            $key,
            $value,
            $createdAt,
            $ttl->expiresAt($this->clock),
        );
    }

    public function delete(Key $key): bool
    {
        if ($this->get($key)->isMiss()) {
            return false;
        }

        unset($this->items[$key->identity()]);

        return true;
    }

    public function clear(): void
    {
        $this->items = [];
    }

    public function getMany(iterable $keys): array
    {
        $entries = [];

        foreach ($keys as $key) {
            $entries[] = $this->get($key);
        }

        return $entries;
    }

    public function setMany(iterable $entries, Ttl $ttl): void
    {
        $normalized = [];

        foreach ($entries as $entry) {
            $normalized[] = [
                'key'   => $entry['key'],
                'value' => $entry['value'],
            ];
        }

        $createdAt = $this->clock->now();
        $expiresAt = $ttl->expiresAt($this->clock);

        foreach ($normalized as $entry) {
            $key = $entry['key'];
            $this->items[$key->identity()] = CacheEntry::hit(
                $key,
                $entry['value'],
                $createdAt,
                $expiresAt,
            );
        }
    }

    public function deleteMany(iterable $keys): bool
    {
        $deleted = true;

        foreach ($keys as $key) {
            $deleted = $this->delete($key) && $deleted;
        }

        return $deleted;
    }

    public function touch(Key $key, Ttl $ttl): bool
    {
        $entry = $this->get($key);
        if ($entry->isMiss()) {
            return false;
        }

        $this->items[$key->identity()] = CacheEntry::hit(
            $key,
            $entry->value(),
            $entry->createdAt() ?? $this->clock->now(),
            $ttl->expiresAt($this->clock),
        );

        return true;
    }

    public function prune(): int
    {
        $removed = 0;

        foreach ($this->items as $identity => $entry) {
            if (!$entry->isExpired($this->clock)) {
                continue;
            }

            unset($this->items[$identity]);
            $removed++;
        }

        return $removed;
    }

    public function entries(?Scope $scope = null): iterable
    {
        $this->prune();
        $scope ??= Scope::root();

        foreach ($this->items as $entry) {
            if ($scope->contains($entry->key()->scope())) {
                yield $entry;
            }
        }
    }

    public function clearScope(Scope $scope): void
    {
        if ($scope->isRoot()) {
            $this->clear();

            return;
        }

        foreach ($this->items as $identity => $entry) {
            if ($scope->contains($entry->key()->scope())) {
                unset($this->items[$identity]);
            }
        }
    }
}
