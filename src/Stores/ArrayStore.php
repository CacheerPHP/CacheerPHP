<?php

declare(strict_types=1);

namespace Silviooosilva\CacheerPhp\Stores;

use Silviooosilva\CacheerPhp\Contracts\AtomicStore;
use Silviooosilva\CacheerPhp\Contracts\BatchStore;
use Silviooosilva\CacheerPhp\Contracts\Clock;
use Silviooosilva\CacheerPhp\Contracts\FlushableScopeStore;
use Silviooosilva\CacheerPhp\Contracts\InspectableStore;
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
use Silviooosilva\CacheerPhp\Stores\Support\InProcessLock;
use Silviooosilva\CacheerPhp\Stores\Support\InProcessLockRegistry;
use Silviooosilva\CacheerPhp\Support\SystemClock;
use UnexpectedValueException;

/**
 * Service-free executable reference for the v6 store contracts.
 */
final class ArrayStore implements
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
     * @var array<string, CacheEntry>
     */
    private array $items = [];

    /**
     * @var array<string, array<string, true>>
     */
    private array $tags = [];

    private readonly InProcessLockRegistry $locks;

    public function __construct(private readonly Clock $clock = new SystemClock())
    {
        $this->locks = new InProcessLockRegistry($this->clock);
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

    public function tag(Key $key, string ...$tags): void
    {
        foreach ($tags as $tag) {
            $this->tags[$tag][$key->identity()] = true;
        }
    }

    public function clearTag(string $tag): int
    {
        $removed = 0;

        foreach (array_keys($this->tags[$tag] ?? []) as $identity) {
            if (isset($this->items[$identity])) {
                unset($this->items[$identity]);
                $removed++;
            }
        }

        unset($this->tags[$tag]);

        return $removed;
    }

    public function increment(Key $key, int $amount = 1, ?int $initial = null, ?Ttl $ttl = null): int
    {
        $entry = $this->get($key);

        if ($entry->isHit()) {
            $current = $entry->value();
            if (!is_int($current)) {
                throw new StoreOperationFailedException(
                    'increment',
                    $key,
                    new UnexpectedValueException('Cannot increment a non-integer cache value.'),
                );
            }
            $next = $current + $amount;
            $expiresAt = $ttl?->expiresAt($this->clock) ?? $entry->expiresAt();
        } else {
            $next = ($initial ?? 0) + $amount;
            $expiresAt = $ttl?->expiresAt($this->clock);
        }

        $this->items[$key->identity()] = CacheEntry::hit($key, $next, $this->clock->now(), $expiresAt);

        return $next;
    }

    public function compareAndSwap(Key $key, mixed $expected, mixed $value, ?Ttl $ttl = null): bool
    {
        $entry = $this->get($key);

        if ($entry->isMiss() || $entry->value() !== $expected) {
            return false;
        }

        $expiresAt = $ttl?->expiresAt($this->clock) ?? $entry->expiresAt();
        $this->items[$key->identity()] = CacheEntry::hit($key, $value, $this->clock->now(), $expiresAt);

        return true;
    }

    public function lock(string $name, Ttl $ttl): Lock
    {
        return new InProcessLock($this->locks, $this->clock, $name, $ttl);
    }
}
