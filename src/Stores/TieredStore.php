<?php

declare(strict_types=1);

namespace Silviooosilva\CacheerPhp\Stores;

use Silviooosilva\CacheerPhp\Contracts\AtomicStore;
use Silviooosilva\CacheerPhp\Contracts\BatchStore;
use Silviooosilva\CacheerPhp\Contracts\CapabilityAware;
use Silviooosilva\CacheerPhp\Contracts\Clock;
use Silviooosilva\CacheerPhp\Contracts\EventDispatcher;
use Silviooosilva\CacheerPhp\Contracts\FlushableScopeStore;
use Silviooosilva\CacheerPhp\Contracts\InspectableStore;
use Silviooosilva\CacheerPhp\Contracts\Lock;
use Silviooosilva\CacheerPhp\Contracts\LockingStore;
use Silviooosilva\CacheerPhp\Contracts\PrunableStore;
use Silviooosilva\CacheerPhp\Contracts\Store;
use Silviooosilva\CacheerPhp\Contracts\TaggableStore;
use Silviooosilva\CacheerPhp\Contracts\TouchStore;
use Silviooosilva\CacheerPhp\Kernel\CacheEntry;
use Silviooosilva\CacheerPhp\Kernel\Capabilities;
use Silviooosilva\CacheerPhp\Kernel\Key;
use Silviooosilva\CacheerPhp\Kernel\Scope;
use Silviooosilva\CacheerPhp\Kernel\Ttl;
use Silviooosilva\CacheerPhp\Observability\CacheEvent;
use Silviooosilva\CacheerPhp\Observability\NullEventDispatcher;
use Silviooosilva\CacheerPhp\Support\SystemClock;

/**
 * Two-tier cache: a fast worker-local L1 in front of a shared L2.
 *
 * Reads check L1, then L2 (promoting a hit into L1 with a capped TTL); writes go
 * through to both. L2 is the source of truth for capabilities that must be
 * shared (locks, tags, atomic counters). Coherence across workers comes from a
 * generation token stored in L2: any bulk invalidation (clear, clearScope,
 * clearTag) bumps it, and each worker flushes its local L1 the next time it
 * notices the token moved — so a long-running worker cannot serve L1 data that
 * another worker already invalidated.
 */
final class TieredStore implements
    Store,
    BatchStore,
    TouchStore,
    PrunableStore,
    InspectableStore,
    FlushableScopeStore,
    TaggableStore,
    AtomicStore,
    LockingStore,
    CapabilityAware
{
    private const GENERATION_KEY = '__cacheer_tier_generation__';

    private int $localGeneration = 0;

    private float $generationCheckedAt = 0.0;

    private bool $generationInitialized = false;

    public function __construct(
        private readonly Store $l1,
        private readonly Store $l2,
        private readonly Clock $clock = new SystemClock(),
        private readonly ?Ttl $l1MaxTtl = null,
        private readonly float $generationCheckSeconds = 5.0,
        private readonly EventDispatcher $events = new NullEventDispatcher(),
    ) {
    }

    /**
     * L2 is the source of truth for every shared capability, so that is what
     * decides. Batching is implemented here over the two tiers' core methods and
     * is always available.
     */
    public function supports(string $capability): bool
    {
        if ($capability === Store::class || $capability === BatchStore::class) {
            return true;
        }

        return Capabilities::supports($this->l2, $capability);
    }

    public function get(Key $key): CacheEntry
    {
        $this->syncGeneration();

        $local = $this->l1->get($key);
        if ($local->isHit()) {
            return $local;
        }

        $shared = $this->l2->get($key);
        if ($shared->isHit()) {
            $this->promote($key, $shared);
        }

        return $shared;
    }

    public function set(Key $key, mixed $value, Ttl $ttl): void
    {
        $this->l2->set($key, $value, $ttl);
        $this->l1->set($key, $value, $this->capForL1($ttl));
    }

    public function delete(Key $key): bool
    {
        $local = $this->l1->delete($key);
        $shared = $this->l2->delete($key);

        return $shared || $local;
    }

    public function clear(): void
    {
        $next = $this->readGeneration() + 1;
        $this->l2->clear();
        $this->l1->clear();
        $this->writeGeneration($next);
        $this->localGeneration = $next;
        $this->generationCheckedAt = $this->clock->nowFloat();
    }

    public function getMany(iterable $keys): array
    {
        $this->syncGeneration();
        $entries = [];

        foreach ($keys as $key) {
            $local = $this->l1->get($key);
            if ($local->isHit()) {
                $entries[] = $local;

                continue;
            }

            $shared = $this->l2->get($key);
            if ($shared->isHit()) {
                $this->promote($key, $shared);
            }
            $entries[] = $shared;
        }

        return $entries;
    }

    public function setMany(iterable $entries, Ttl $ttl): void
    {
        foreach ($entries as $entry) {
            $this->l2->set($entry['key'], $entry['value'], $ttl);
            $this->l1->set($entry['key'], $entry['value'], $this->capForL1($ttl));
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
        $touched = $this->sharedTouch()->touch($key, $ttl);
        if ($touched) {
            $this->l1->delete($key);
        }

        return $touched;
    }

    public function prune(): int
    {
        $local = $this->l1;
        if ($local instanceof PrunableStore && Capabilities::supports($local, PrunableStore::class)) {
            $local->prune();
        }

        return $this->sharedPrune()->prune();
    }

    public function entries(?Scope $scope = null): iterable
    {
        foreach ($this->sharedInspect()->entries($scope) as $entry) {
            if ($entry->key()->value() === self::GENERATION_KEY && $entry->key()->scope()->isRoot()) {
                continue;
            }

            yield $entry;
        }
    }

    public function clearScope(Scope $scope): void
    {
        if ($scope->isRoot()) {
            $this->clear();

            return;
        }

        $this->sharedScopeFlush()->clearScope($scope);
        $this->invalidateLocalCache();
    }

    public function tag(Key $key, string ...$tags): void
    {
        $this->sharedTaggable()->tag($key, ...$tags);
    }

    public function clearTag(string $tag): int
    {
        $removed = $this->sharedTaggable()->clearTag($tag);
        $this->invalidateLocalCache();

        return $removed;
    }

    public function increment(Key $key, int $amount = 1, ?int $initial = null, ?Ttl $ttl = null): int
    {
        $value = $this->sharedAtomic()->increment($key, $amount, $initial, $ttl);
        $this->l1->delete($key);

        return $value;
    }

    public function compareAndSwap(Key $key, mixed $expected, mixed $value, ?Ttl $ttl = null): bool
    {
        $swapped = $this->sharedAtomic()->compareAndSwap($key, $expected, $value, $ttl);
        if ($swapped) {
            $this->l1->delete($key);
        }

        return $swapped;
    }

    public function lock(string $name, Ttl $ttl): Lock
    {
        return $this->sharedLocking()->lock($name, $ttl);
    }

    private function promote(Key $key, CacheEntry $entry): void
    {
        $this->l1->set($key, $entry->value(), $this->promotionTtl($entry));
        $this->events->dispatch(CacheEvent::promoted('TieredStore', (string) $key));
    }

    private function promotionTtl(CacheEntry $entry): Ttl
    {
        $expiresAt = $entry->expiresAt();
        $base = $expiresAt === null
            ? Ttl::forever()
            : Ttl::seconds(max(1, $expiresAt - $this->clock->now()));

        return $this->capForL1($base);
    }

    private function capForL1(Ttl $ttl): Ttl
    {
        if ($this->l1MaxTtl === null) {
            return $ttl;
        }

        $max = $this->l1MaxTtl->inSeconds();
        if ($max === null) {
            return $ttl;
        }

        $seconds = $ttl->inSeconds();

        return $seconds === null ? $this->l1MaxTtl : Ttl::seconds(min($seconds, $max));
    }

    private function syncGeneration(): void
    {
        $now = $this->clock->nowFloat();

        if ($this->generationInitialized && ($now - $this->generationCheckedAt) < $this->generationCheckSeconds) {
            return;
        }

        $current = $this->readGeneration();
        if ($this->generationInitialized && $current !== $this->localGeneration) {
            $this->l1->clear();
        }

        $this->localGeneration = $current;
        $this->generationCheckedAt = $now;
        $this->generationInitialized = true;
    }

    private function invalidateLocalCache(): void
    {
        $next = $this->readGeneration() + 1;
        $this->writeGeneration($next);
        $this->l1->clear();
        $this->localGeneration = $next;
        $this->generationCheckedAt = $this->clock->nowFloat();
    }

    private function readGeneration(): int
    {
        $entry = $this->l2->get($this->generationKey());
        $value = $entry->valueOr(0);

        return is_int($value) ? $value : 0;
    }

    private function writeGeneration(int $generation): void
    {
        $this->l2->set($this->generationKey(), $generation, Ttl::forever());
    }

    private function generationKey(): Key
    {
        return Key::named(self::GENERATION_KEY);
    }

    private function sharedTouch(): TouchStore
    {
        return Capabilities::require($this->l2, TouchStore::class, 'touch');
    }

    private function sharedPrune(): PrunableStore
    {
        return Capabilities::require($this->l2, PrunableStore::class, 'prune');
    }

    private function sharedInspect(): InspectableStore
    {
        return Capabilities::require($this->l2, InspectableStore::class, 'entries');
    }

    private function sharedScopeFlush(): FlushableScopeStore
    {
        return Capabilities::require($this->l2, FlushableScopeStore::class, 'clearScope');
    }

    private function sharedTaggable(): TaggableStore
    {
        return Capabilities::require($this->l2, TaggableStore::class, 'tag');
    }

    private function sharedAtomic(): AtomicStore
    {
        return Capabilities::require($this->l2, AtomicStore::class, 'increment');
    }

    private function sharedLocking(): LockingStore
    {
        return Capabilities::require($this->l2, LockingStore::class, 'lock');
    }
}
