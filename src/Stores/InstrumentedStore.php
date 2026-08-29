<?php

declare(strict_types=1);

namespace Silviooosilva\CacheerPhp\Stores;

use Silviooosilva\CacheerPhp\Contracts\AtomicStore;
use Silviooosilva\CacheerPhp\Contracts\BatchStore;
use Silviooosilva\CacheerPhp\Contracts\CapabilityAware;
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
use Throwable;

/**
 * Transparent instrumentation decorator: times every operation and emits typed
 * events, without changing behavior.
 *
 * Cache values are never placed in events unless value capture is explicitly
 * enabled, and even then an optional redactor runs first. The serialized byte
 * size is always recorded (size, not contents). A store failure emits a failure
 * event and is re-raised unchanged.
 */
final class InstrumentedStore implements
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
    /**
     * @var string
     */
    private readonly string $name;

    /**
     * @param Store $inner
     * @param EventDispatcher $events
     * @param bool $captureValues
     * @param (callable(mixed): mixed)|null $redactor
     */
    public function __construct(
        private readonly Store $inner,
        private readonly EventDispatcher $events,
        private readonly bool $captureValues = false,
        private $redactor = null,
    ) {
        $this->name = (new \ReflectionClass($inner))->getShortName();
    }

    /**
     * Batching is implemented here over the four core methods, so it is always
     * available. Every other capability is only as real as the wrapped store's.
     *
     * @param string $capability
     * @return bool
     */
    public function supports(string $capability): bool
    {
        if ($capability === Store::class || $capability === BatchStore::class) {
            return true;
        }

        return Capabilities::supports($this->inner, $capability);
    }

    /**
     * @param Key $key
     * @return CacheEntry
     */
    public function get(Key $key): CacheEntry
    {
        $start = microtime(true);
        $entry = $this->guard($key, fn (): CacheEntry => $this->inner->get($key));
        $duration = $this->elapsed($start);

        if ($entry->isHit()) {
            [$bytes, $hasValue, $value] = $this->capture($entry->value());
            $this->events->dispatch(CacheEvent::hit($this->name, (string) $key, $duration, $bytes, $hasValue, $value));
        } else {
            $this->events->dispatch(CacheEvent::miss($this->name, (string) $key, $duration));
        }

        return $entry;
    }

    /**
     * @param Key $key
     * @param mixed $value
     * @param Ttl $ttl
     */
    public function set(Key $key, mixed $value, Ttl $ttl): void
    {
        $start = microtime(true);
        $this->guard($key, function () use ($key, $value, $ttl): void {
            $this->inner->set($key, $value, $ttl);
        });

        [$bytes, $hasValue, $captured] = $this->capture($value);
        $this->events->dispatch(CacheEvent::written($this->name, (string) $key, $this->elapsed($start), $bytes, $hasValue, $captured));
    }

    /**
     * @param Key $key
     * @return bool
     */
    public function delete(Key $key): bool
    {
        $start = microtime(true);
        $existed = $this->guard($key, fn (): bool => $this->inner->delete($key));
        $this->events->dispatch(CacheEvent::deleted($this->name, (string) $key, $this->elapsed($start), $existed));

        return $existed;
    }

    public function clear(): void
    {
        $start = microtime(true);
        $this->guard(null, function (): void {
            $this->inner->clear();
        });
        $this->events->dispatch(CacheEvent::cleared($this->name, $this->elapsed($start)));
    }

    /**
     * @param iterable<Key> $keys
     * @return list<CacheEntry>
     */
    public function getMany(iterable $keys): array
    {
        $entries = [];
        foreach ($keys as $key) {
            $entries[] = $this->get($key);
        }

        return $entries;
    }

    /**
     * @param iterable $entries
     * @param Ttl $ttl
     */
    public function setMany(iterable $entries, Ttl $ttl): void
    {
        foreach ($entries as $entry) {
            $this->set($entry['key'], $entry['value'], $ttl);
        }
    }

    /**
     * @param iterable<Key> $keys
     * @return bool
     */
    public function deleteMany(iterable $keys): bool
    {
        $deleted = true;
        foreach ($keys as $key) {
            $deleted = $this->delete($key) && $deleted;
        }

        return $deleted;
    }

    /**
     * @param Key $key
     * @param Ttl $ttl
     * @return bool
     */
    public function touch(Key $key, Ttl $ttl): bool
    {
        $start = microtime(true);
        $touched = $this->guard($key, fn (): bool => $this->touchable()->touch($key, $ttl));

        // Reported as a write: no value changed, but the entry did. Without this
        // a renewed TTL is invisible to telemetry.
        if ($touched) {
            $this->events->dispatch(CacheEvent::written($this->name, (string) $key, $this->elapsed($start)));
        }

        return $touched;
    }

    /**
     * @return int
     */
    public function prune(): int
    {
        $start = microtime(true);
        $removed = $this->prunable()->prune();
        $this->events->dispatch(CacheEvent::pruned($this->name, $this->elapsed($start), $removed));

        return $removed;
    }

    /**
     * @param ?Scope $scope
     * @return iterable<CacheEntry>
     */
    public function entries(?Scope $scope = null): iterable
    {
        return $this->inspectable()->entries($scope);
    }

    /**
     * @param Scope $scope
     */
    public function clearScope(Scope $scope): void
    {
        $start = microtime(true);
        $this->scopeFlushable()->clearScope($scope);
        $this->events->dispatch(CacheEvent::cleared($this->name, $this->elapsed($start)));
    }

    /**
     * @param Key $key
     * @param string ...$tags
     */
    public function tag(Key $key, string ...$tags): void
    {
        $start = microtime(true);
        $this->guard($key, function () use ($key, $tags): void {
            $this->taggable()->tag($key, ...$tags);
        });

        $this->events->dispatch(CacheEvent::written(
            $this->name,
            (string) $key,
            $this->elapsed($start),
            count: count($tags),
        ));
    }

    /**
     * @param string $tag
     * @return int
     */
    public function clearTag(string $tag): int
    {
        $start = microtime(true);
        $removed = $this->guard(null, fn (): int => $this->taggable()->clearTag($tag));

        // A tag flush is a bulk invalidation, so it reports as a clear carrying
        // how many entries went with it.
        $this->events->dispatch(CacheEvent::pruned($this->name, $this->elapsed($start), $removed));

        return $removed;
    }

    /**
     * @param Key $key
     * @param int $amount
     * @param ?int $initial
     * @param ?Ttl $ttl
     * @return int
     */
    public function increment(Key $key, int $amount = 1, ?int $initial = null, ?Ttl $ttl = null): int
    {
        $start = microtime(true);
        $value = $this->guard($key, fn (): int => $this->atomic()->increment($key, $amount, $initial, $ttl));

        // Counters are writes; the new value rides along in `count` so a
        // dashboard can chart it without value capture being enabled.
        $this->events->dispatch(CacheEvent::written(
            $this->name,
            (string) $key,
            $this->elapsed($start),
            count: $value,
        ));

        return $value;
    }

    /**
     * @param Key $key
     * @param mixed $expected
     * @param mixed $value
     * @param ?Ttl $ttl
     * @return bool
     */
    public function compareAndSwap(Key $key, mixed $expected, mixed $value, ?Ttl $ttl = null): bool
    {
        $start = microtime(true);
        $swapped = $this->guard($key, fn (): bool => $this->atomic()->compareAndSwap($key, $expected, $value, $ttl));

        // Only a successful swap wrote anything.
        if ($swapped) {
            [$bytes, $hasValue, $captured] = $this->capture($value);
            $this->events->dispatch(CacheEvent::written(
                $this->name,
                (string) $key,
                $this->elapsed($start),
                $bytes,
                $hasValue,
                $captured,
            ));
        }

        return $swapped;
    }

    /**
     * @param string $name
     * @param Ttl $ttl
     * @return Lock
     */
    public function lock(string $name, Ttl $ttl): Lock
    {
        return $this->lockable()->lock($name, $ttl);
    }

    /**
     * @template T
     * @param ?Key $key
     * @param callable(): T $operationFn
     * @return T
     */
    private function guard(?Key $key, callable $operationFn): mixed
    {
        $start = microtime(true);

        try {
            return $operationFn();
        } catch (Throwable $exception) {
            $this->events->dispatch(CacheEvent::failed($this->name, $key === null ? null : (string) $key, $this->elapsed($start), $exception));

            throw $exception;
        }
    }

    /**
     * @param mixed $value
     * @return array{0: int|null, 1: bool, 2: mixed}
     */
    private function capture(mixed $value): array
    {
        try {
            $bytes = strlen(serialize($value));
        } catch (Throwable) {
            $bytes = null;
        }

        if (!$this->captureValues) {
            return [$bytes, false, null];
        }

        $redactor = $this->redactor;
        $captured = $redactor === null ? $value : $redactor($value);

        return [$bytes, true, $captured];
    }

    /**
     * @param float $start
     * @return float
     */
    private function elapsed(float $start): float
    {
        return (microtime(true) - $start) * 1_000_000;
    }

    /**
     * @return TouchStore
     */
    private function touchable(): TouchStore
    {
        return Capabilities::require($this->inner, TouchStore::class, 'touch');
    }

    /**
     * @return PrunableStore
     */
    private function prunable(): PrunableStore
    {
        return Capabilities::require($this->inner, PrunableStore::class, 'prune');
    }

    /**
     * @return InspectableStore
     */
    private function inspectable(): InspectableStore
    {
        return Capabilities::require($this->inner, InspectableStore::class, 'entries');
    }

    /**
     * @return FlushableScopeStore
     */
    private function scopeFlushable(): FlushableScopeStore
    {
        return Capabilities::require($this->inner, FlushableScopeStore::class, 'clearScope');
    }

    /**
     * @return TaggableStore
     */
    private function taggable(): TaggableStore
    {
        return Capabilities::require($this->inner, TaggableStore::class, 'tag');
    }

    /**
     * @return AtomicStore
     */
    private function atomic(): AtomicStore
    {
        return Capabilities::require($this->inner, AtomicStore::class, 'increment');
    }

    /**
     * @return LockingStore
     */
    private function lockable(): LockingStore
    {
        return Capabilities::require($this->inner, LockingStore::class, 'lock');
    }
}
