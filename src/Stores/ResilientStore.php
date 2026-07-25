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
use Silviooosilva\CacheerPhp\Exceptions\UnsupportedCapabilityException;
use Silviooosilva\CacheerPhp\Kernel\CacheEntry;
use Silviooosilva\CacheerPhp\Kernel\Key;
use Silviooosilva\CacheerPhp\Kernel\Scope;
use Silviooosilva\CacheerPhp\Kernel\Ttl;
use Silviooosilva\CacheerPhp\Support\CircuitBreaker;
use Silviooosilva\CacheerPhp\Support\SystemClock;
use Throwable;

/**
 * Fault-tolerant decorator: serve from a primary store, fall back when it fails.
 *
 * A circuit breaker guards the primary. Reads try it while the breaker allows;
 * on error they record the failure and answer from the fallback. Writes are
 * best-effort to the primary and always applied to the fallback, so it stays
 * warm and can serve while the primary is down. This is about failure, not
 * performance — unlike TieredStore, a primary miss is a real miss and is never
 * "recovered" from the fallback.
 */
final class ResilientStore implements
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
    private readonly CircuitBreaker $breaker;

    public function __construct(
        private readonly Store $primary,
        private readonly Store $fallback,
        ?CircuitBreaker $breaker = null,
        Clock $clock = new SystemClock(),
    ) {
        $this->breaker = $breaker ?? new CircuitBreaker($clock);
    }

    public function get(Key $key): CacheEntry
    {
        return $this->read(fn (Store $store): CacheEntry => $store->get($key));
    }

    public function set(Key $key, mixed $value, Ttl $ttl): void
    {
        $this->write(fn (Store $store) => $store->set($key, $value, $ttl));
    }

    public function delete(Key $key): bool
    {
        return $this->write(fn (Store $store): bool => $store->delete($key));
    }

    public function clear(): void
    {
        $this->write(fn (Store $store) => $store->clear());
    }

    public function getMany(iterable $keys): array
    {
        $keys = $this->materialize($keys);

        return $this->read(fn (Store $store): array => $this->batch($store)->getMany($keys));
    }

    public function setMany(iterable $entries, Ttl $ttl): void
    {
        $entries = $this->materialize($entries);
        $this->write(fn (Store $store) => $this->batch($store)->setMany($entries, $ttl));
    }

    public function deleteMany(iterable $keys): bool
    {
        $keys = $this->materialize($keys);

        return $this->write(fn (Store $store): bool => $this->batch($store)->deleteMany($keys));
    }

    public function touch(Key $key, Ttl $ttl): bool
    {
        return $this->write(fn (Store $store): bool => $this->touchable($store)->touch($key, $ttl));
    }

    public function prune(): int
    {
        return $this->write(fn (Store $store): int => $this->prunable($store)->prune());
    }

    public function entries(?Scope $scope = null): iterable
    {
        return $this->read(fn (Store $store): iterable => iterator_to_array(
            $this->inspectable($store)->entries($scope),
            false,
        ));
    }

    public function clearScope(Scope $scope): void
    {
        $this->write(fn (Store $store) => $this->scopeFlushable($store)->clearScope($scope));
    }

    public function tag(Key $key, string ...$tags): void
    {
        $this->write(fn (Store $store) => $this->taggable($store)->tag($key, ...$tags));
    }

    public function clearTag(string $tag): int
    {
        return $this->write(fn (Store $store): int => $this->taggable($store)->clearTag($tag));
    }

    public function increment(Key $key, int $amount = 1, ?int $initial = null, ?Ttl $ttl = null): int
    {
        return $this->write(fn (Store $store): int => $this->atomic($store)->increment($key, $amount, $initial, $ttl));
    }

    public function compareAndSwap(Key $key, mixed $expected, mixed $value, ?Ttl $ttl = null): bool
    {
        return $this->write(fn (Store $store): bool => $this->atomic($store)->compareAndSwap($key, $expected, $value, $ttl));
    }

    public function lock(string $name, Ttl $ttl): Lock
    {
        $store = $this->breaker->canAttempt() ? $this->primary : $this->fallback;

        return $this->lockable($store)->lock($name, $ttl);
    }

    /**
     * Health snapshot safe to log or expose: breaker state only, never any
     * connection details.
     *
     * @return array{state: string, healthy: bool}
     */
    public function health(): array
    {
        return ['state' => $this->breaker->state(), 'healthy' => $this->breaker->isHealthy()];
    }

    /**
     * @template T
     * @param callable(Store): T $operation
     * @return T
     */
    private function read(callable $operation): mixed
    {
        if ($this->breaker->canAttempt()) {
            try {
                $result = $operation($this->primary);
                $this->breaker->recordSuccess();

                return $result;
            } catch (Throwable) {
                $this->breaker->recordFailure();
            }
        }

        return $operation($this->fallback);
    }

    /**
     * @template T
     * @param callable(Store): T $operation
     * @return T
     */
    private function write(callable $operation): mixed
    {
        if ($this->breaker->canAttempt()) {
            try {
                $operation($this->primary);
                $this->breaker->recordSuccess();
            } catch (Throwable) {
                $this->breaker->recordFailure();
            }
        }

        return $operation($this->fallback);
    }

    /**
     * @param iterable<mixed> $items
     * @return list<mixed>
     */
    private function materialize(iterable $items): array
    {
        return is_array($items) ? array_values($items) : iterator_to_array($items, false);
    }

    private function batch(Store $store): BatchStore
    {
        return $store instanceof BatchStore
            ? $store
            : throw UnsupportedCapabilityException::for(BatchStore::class, 'batch');
    }

    private function touchable(Store $store): TouchStore
    {
        return $store instanceof TouchStore
            ? $store
            : throw UnsupportedCapabilityException::for(TouchStore::class, 'touch');
    }

    private function prunable(Store $store): PrunableStore
    {
        return $store instanceof PrunableStore
            ? $store
            : throw UnsupportedCapabilityException::for(PrunableStore::class, 'prune');
    }

    private function inspectable(Store $store): InspectableStore
    {
        return $store instanceof InspectableStore
            ? $store
            : throw UnsupportedCapabilityException::for(InspectableStore::class, 'entries');
    }

    private function scopeFlushable(Store $store): FlushableScopeStore
    {
        return $store instanceof FlushableScopeStore
            ? $store
            : throw UnsupportedCapabilityException::for(FlushableScopeStore::class, 'clearScope');
    }

    private function taggable(Store $store): TaggableStore
    {
        return $store instanceof TaggableStore
            ? $store
            : throw UnsupportedCapabilityException::for(TaggableStore::class, 'tag');
    }

    private function atomic(Store $store): AtomicStore
    {
        return $store instanceof AtomicStore
            ? $store
            : throw UnsupportedCapabilityException::for(AtomicStore::class, 'increment');
    }

    private function lockable(Store $store): LockingStore
    {
        return $store instanceof LockingStore
            ? $store
            : throw UnsupportedCapabilityException::for(LockingStore::class, 'lock');
    }
}
