<?php

declare(strict_types=1);

namespace Silviooosilva\CacheerPhp\Stores;

use Silviooosilva\CacheerPhp\Contracts\AtomicStore;
use Silviooosilva\CacheerPhp\Contracts\BatchStore;
use Silviooosilva\CacheerPhp\Contracts\EventDispatcher;
use Silviooosilva\CacheerPhp\Contracts\FlushableScopeStore;
use Silviooosilva\CacheerPhp\Contracts\InspectableStore;
use Silviooosilva\CacheerPhp\Contracts\Lock;
use Silviooosilva\CacheerPhp\Contracts\LockingStore;
use Silviooosilva\CacheerPhp\Contracts\PrunableStore;
use Silviooosilva\CacheerPhp\Contracts\Store;
use Silviooosilva\CacheerPhp\Contracts\TaggableStore;
use Silviooosilva\CacheerPhp\Contracts\TouchStore;
use Silviooosilva\CacheerPhp\Exceptions\UnsupportedCapabilityException;
use Silviooosilva\CacheerPhp\Kernel\CacheEntry;
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
    LockingStore
{
    private readonly string $name;

    /**
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

    public function set(Key $key, mixed $value, Ttl $ttl): void
    {
        $start = microtime(true);
        $this->guard($key, function () use ($key, $value, $ttl): void {
            $this->inner->set($key, $value, $ttl);
        });

        [$bytes, $hasValue, $captured] = $this->capture($value);
        $this->events->dispatch(CacheEvent::written($this->name, (string) $key, $this->elapsed($start), $bytes, $hasValue, $captured));
    }

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
        foreach ($entries as $entry) {
            $this->set($entry['key'], $entry['value'], $ttl);
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
        return $this->touchable()->touch($key, $ttl);
    }

    public function prune(): int
    {
        $start = microtime(true);
        $removed = $this->prunable()->prune();
        $this->events->dispatch(CacheEvent::pruned($this->name, $this->elapsed($start), $removed));

        return $removed;
    }

    public function entries(?Scope $scope = null): iterable
    {
        return $this->inspectable()->entries($scope);
    }

    public function clearScope(Scope $scope): void
    {
        $start = microtime(true);
        $this->scopeFlushable()->clearScope($scope);
        $this->events->dispatch(CacheEvent::cleared($this->name, $this->elapsed($start)));
    }

    public function tag(Key $key, string ...$tags): void
    {
        $this->taggable()->tag($key, ...$tags);
    }

    public function clearTag(string $tag): int
    {
        return $this->taggable()->clearTag($tag);
    }

    public function increment(Key $key, int $amount = 1, ?int $initial = null, ?Ttl $ttl = null): int
    {
        return $this->atomic()->increment($key, $amount, $initial, $ttl);
    }

    public function compareAndSwap(Key $key, mixed $expected, mixed $value, ?Ttl $ttl = null): bool
    {
        return $this->atomic()->compareAndSwap($key, $expected, $value, $ttl);
    }

    public function lock(string $name, Ttl $ttl): Lock
    {
        return $this->lockable()->lock($name, $ttl);
    }

    /**
     * @template T
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

    private function elapsed(float $start): float
    {
        return (microtime(true) - $start) * 1_000_000;
    }

    private function touchable(): TouchStore
    {
        return $this->inner instanceof TouchStore
            ? $this->inner
            : throw UnsupportedCapabilityException::for(TouchStore::class, 'touch');
    }

    private function prunable(): PrunableStore
    {
        return $this->inner instanceof PrunableStore
            ? $this->inner
            : throw UnsupportedCapabilityException::for(PrunableStore::class, 'prune');
    }

    private function inspectable(): InspectableStore
    {
        return $this->inner instanceof InspectableStore
            ? $this->inner
            : throw UnsupportedCapabilityException::for(InspectableStore::class, 'entries');
    }

    private function scopeFlushable(): FlushableScopeStore
    {
        return $this->inner instanceof FlushableScopeStore
            ? $this->inner
            : throw UnsupportedCapabilityException::for(FlushableScopeStore::class, 'clearScope');
    }

    private function taggable(): TaggableStore
    {
        return $this->inner instanceof TaggableStore
            ? $this->inner
            : throw UnsupportedCapabilityException::for(TaggableStore::class, 'tag');
    }

    private function atomic(): AtomicStore
    {
        return $this->inner instanceof AtomicStore
            ? $this->inner
            : throw UnsupportedCapabilityException::for(AtomicStore::class, 'increment');
    }

    private function lockable(): LockingStore
    {
        return $this->inner instanceof LockingStore
            ? $this->inner
            : throw UnsupportedCapabilityException::for(LockingStore::class, 'lock');
    }
}
