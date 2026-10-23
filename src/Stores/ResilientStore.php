<?php

declare(strict_types=1);

namespace Silviooosilva\CacheerPhp\Stores;

use RuntimeException;
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
use Silviooosilva\CacheerPhp\Exceptions\StoreOperationFailedException;
use Silviooosilva\CacheerPhp\Kernel\CacheEntry;
use Silviooosilva\CacheerPhp\Kernel\Capabilities;
use Silviooosilva\CacheerPhp\Kernel\Key;
use Silviooosilva\CacheerPhp\Kernel\Scope;
use Silviooosilva\CacheerPhp\Kernel\Ttl;
use Silviooosilva\CacheerPhp\Observability\CacheEvent;
use Silviooosilva\CacheerPhp\Observability\NullEventDispatcher;
use Silviooosilva\CacheerPhp\Observability\SafeDispatch;
use Silviooosilva\CacheerPhp\Stores\Support\BackendFailure;
use Silviooosilva\CacheerPhp\Stores\Support\FailClosedLock;
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
    LockingStore,
    CapabilityAware
{
    /**
     * @var CircuitBreaker
     */
    private readonly CircuitBreaker $breaker;

    /**
     * Writes made while the primary was unreachable are tracked (up to this
     * many keys and bulk operations) so they can be invalidated on the primary
     * once it is back; past the limit the primary is cleared instead.
     */
    private const MAX_OUTAGE_WRITES = 1000;

    /**
     * @var array<string, Key> keys written or deleted on the fallback alone
     */
    private array $outageKeys = [];

    /**
     * @var list<array{0: 'scope', 1: Scope}|array{0: 'tag', 1: string}> bulk invalidations made on the fallback alone
     */
    private array $outageBulk = [];

    /**
     * Whether the primary must be cleared on recovery (an outage clear(), or
     * too many tracked writes).
     */
    private bool $clearPrimaryOnRecovery = false;

    /**
     * Whether an outage has been reported and not yet its end, so half-open
     * retries that fail again do not report a new outage each time.
     */
    private bool $outageReported = false;

    /**
     * @param Store $primary
     * @param Store $fallback
     * @param ?CircuitBreaker $breaker
     * @param Clock $clock
     * @param EventDispatcher $events Receives outage, fallback, and reconciliation events.
     */
    public function __construct(
        private readonly Store $primary,
        private readonly Store $fallback,
        ?CircuitBreaker $breaker = null,
        Clock $clock = new SystemClock(),
        private readonly EventDispatcher $events = new NullEventDispatcher(),
    ) {
        $this->breaker = $breaker ?? new CircuitBreaker($clock);
    }

    /**
     * Every operation may land on either store — writes always reach the
     * fallback — so a capability is only real when both stores honor it.
     *
     * @param string $capability
     * @return bool
     */
    public function supports(string $capability): bool
    {
        if ($capability === Store::class) {
            return true;
        }

        // Counters and locks run on the primary alone (see onPrimary(), lock()),
        // so only the primary has to provide them.
        if ($capability === AtomicStore::class || $capability === LockingStore::class) {
            return Capabilities::supports($this->primary, $capability);
        }

        return Capabilities::supports($this->primary, $capability)
            && Capabilities::supports($this->fallback, $capability);
    }

    /**
     * @param Key $key
     * @return CacheEntry
     */
    public function get(Key $key): CacheEntry
    {
        return $this->read('get', fn (Store $store): CacheEntry => $store->get($key), $key);
    }

    /**
     * @param Key $key
     * @param mixed $value
     * @param Ttl $ttl
     */
    public function set(Key $key, mixed $value, Ttl $ttl): void
    {
        $this->write('set', fn (Store $store) => $store->set($key, $value, $ttl), [$key]);
    }

    /**
     * @param Key $key
     * @return bool
     */
    public function delete(Key $key): bool
    {
        return $this->write('delete', fn (Store $store): bool => $store->delete($key), [$key]);
    }

    public function clear(): void
    {
        $this->write('clear', fn (Store $store) => $store->clear(), clearsAll: true);
    }

    /**
     * @param iterable<Key> $keys
     * @return list<CacheEntry>
     */
    public function getMany(iterable $keys): array
    {
        $keys = $this->materialize($keys);

        return $this->read('getMany', fn (Store $store): array => $this->batch($store)->getMany($keys), count: count($keys));
    }

    /**
     * @param iterable $entries
     * @param Ttl $ttl
     */
    public function setMany(iterable $entries, Ttl $ttl): void
    {
        $entries = $this->materialize($entries);
        $this->write('setMany', fn (Store $store) => $this->batch($store)->setMany($entries, $ttl), array_column($entries, 'key'));
    }

    /**
     * @param iterable<Key> $keys
     * @return bool
     */
    public function deleteMany(iterable $keys): bool
    {
        $keys = $this->materialize($keys);

        return $this->write('deleteMany', fn (Store $store): bool => $this->batch($store)->deleteMany($keys), $keys);
    }

    /**
     * @param Key $key
     * @param Ttl $ttl
     * @return bool
     */
    public function touch(Key $key, Ttl $ttl): bool
    {
        return $this->write('touch', fn (Store $store): bool => $this->touchable($store)->touch($key, $ttl), [$key]);
    }

    /**
     * @return int
     */
    public function prune(): int
    {
        return $this->write('prune', fn (Store $store): int => $this->prunable($store)->prune());
    }

    /**
     * @param ?Scope $scope
     * @return iterable<CacheEntry>
     */
    public function entries(?Scope $scope = null): iterable
    {
        return $this->read('entries', fn (Store $store): iterable => iterator_to_array(
            $this->inspectable($store)->entries($scope),
            false,
        ));
    }

    /**
     * @param Scope $scope
     */
    public function clearScope(Scope $scope): void
    {
        $this->write('clearScope', fn (Store $store) => $this->scopeFlushable($store)->clearScope($scope), bulk: ['scope', $scope]);
    }

    /**
     * @param Key $key
     * @param string ...$tags
     */
    public function tag(Key $key, string ...$tags): void
    {
        $this->write('tag', fn (Store $store) => $this->taggable($store)->tag($key, ...$tags), [$key]);
    }

    /**
     * @param string $tag
     * @return int
     */
    public function clearTag(string $tag): int
    {
        return $this->write('clearTag', fn (Store $store): int => $this->taggable($store)->clearTag($tag), bulk: ['tag', $tag]);
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
        return $this->onPrimary('increment', $key, fn (Store $store): int => $this->atomic($store)->increment($key, $amount, $initial, $ttl));
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
        return $this->onPrimary('compareAndSwap', $key, fn (Store $store): bool => $this->atomic($store)->compareAndSwap($key, $expected, $value, $ttl));
    }

    /**
     * @param string $name
     * @param Ttl $ttl
     * @return Lock
     */
    public function lock(string $name, Ttl $ttl): Lock
    {
        if (!$this->breaker->canAttempt()) {
            return new FailClosedLock(null, $this->breaker);
        }

        try {
            return new FailClosedLock($this->lockable($this->primary)->lock($name, $ttl), $this->breaker, $this->reportOutageState(...));
        } catch (Throwable $error) {
            if (!BackendFailure::isOutage($error)) {
                throw $error;
            }

            $this->primaryFailed($error);

            return new FailClosedLock(null, $this->breaker);
        }
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
     * @param string $name
     * @param callable(Store): T $operation
     * @param ?Key $key the key read, when there is one
     * @param int $count how many keys the read covers
     * @return T
     */
    private function read(string $name, callable $operation, ?Key $key = null, int $count = 1): mixed
    {
        if ($this->breaker->canAttempt()) {
            try {
                $this->reconcile();
                $result = $operation($this->primary);
                $this->primarySucceeded();

                return $result;
            } catch (Throwable $error) {
                if (!BackendFailure::isOutage($error)) {
                    throw $error;
                }

                $this->primaryFailed($error);
            }
        }

        $result = $operation($this->fallback);
        $this->reportFallback($name, $key === null ? [] : [$key], $count);

        return $result;
    }

    /**
     * Writes to the primary (authoritative) and mirrors to the fallback; while
     * the primary is unavailable, writes to the fallback alone and records what
     * changed so reconcile() can invalidate it on the primary later.
     *
     * @template T
     * @param string $name
     * @param callable(Store): T $operation
     * @param list<Key> $keys the keys this write changes
     * @param array{0: 'scope', 1: Scope}|array{0: 'tag', 1: string}|null $bulk a bulk invalidation it performs
     * @param bool $clearsAll whether it clears the whole store
     * @return T
     */
    private function write(string $name, callable $operation, array $keys = [], ?array $bulk = null, bool $clearsAll = false): mixed
    {
        $primaryWritten = false;
        $result = null;

        if ($this->breaker->canAttempt()) {
            try {
                $this->reconcile();
                $result = $operation($this->primary);
                $this->primarySucceeded();
                $primaryWritten = true;
            } catch (Throwable $error) {
                if (!BackendFailure::isOutage($error)) {
                    throw $error;
                }

                $this->primaryFailed($error);
            }
        }

        if ($primaryWritten) {
            // The primary is authoritative; the fallback is a best-effort mirror
            // kept warm for the next outage, so its unavailability is ignored.
            try {
                $operation($this->fallback);
            } catch (Throwable $mirrorError) {
                if (!BackendFailure::isOutage($mirrorError)) {
                    throw $mirrorError;
                }
            }

            return $result;
        }

        // The primary was skipped or unreachable: the fallback's result stands,
        // and the write is remembered so the primary can be reconciled later.
        $result = $operation($this->fallback);
        $this->recordOutageWrite($keys, $bulk, $clearsAll);
        $this->reportFallback($name, $keys, max(1, count($keys)), $bulk !== null && $bulk[0] === 'scope' ? $bulk[1] : null);

        return $result;
    }

    /**
     * Runs a counter or compare-and-swap on the primary alone. Mirroring it to
     * the fallback would produce an independent, conflicting counter, so while
     * the primary is unavailable the operation fails closed; after it succeeds,
     * the fallback's copy of the key is dropped so an outage never serves a
     * stale value.
     *
     * @template T
     * @param string $name
     * @param Key $key
     * @param callable(Store): T $operation
     * @return T
     */
    private function onPrimary(string $name, Key $key, callable $operation): mixed
    {
        if (!$this->breaker->canAttempt()) {
            throw new StoreOperationFailedException($name, $key, new RuntimeException('The primary store is unavailable.'));
        }

        try {
            $this->reconcile();
            $result = $operation($this->primary);
            $this->primarySucceeded();
        } catch (Throwable $error) {
            if (!BackendFailure::isOutage($error)) {
                throw $error;
            }

            $this->primaryFailed($error);

            throw new StoreOperationFailedException($name, $key, $error);
        }

        try {
            $this->fallback->delete($key);
        } catch (Throwable $mirrorError) {
            if (!BackendFailure::isOutage($mirrorError)) {
                throw $mirrorError;
            }
        }

        return $result;
    }

    /**
     * @param list<Key> $keys
     * @param array{0: 'scope', 1: Scope}|array{0: 'tag', 1: string}|null $bulk
     * @param bool $clearsAll
     */
    private function recordOutageWrite(array $keys, ?array $bulk, bool $clearsAll): void
    {
        if ($this->clearPrimaryOnRecovery) {
            return;
        }

        if ($bulk !== null) {
            $this->outageBulk[] = $bulk;
        }

        foreach ($keys as $key) {
            $this->outageKeys[$key->identity()] = $key;
        }

        if ($clearsAll || count($this->outageKeys) + count($this->outageBulk) > self::MAX_OUTAGE_WRITES) {
            $this->clearPrimaryOnRecovery = true;
            $this->outageKeys = [];
            $this->outageBulk = [];
        }
    }

    /**
     * Before the primary serves anything after an outage, invalidates on it
     * every key and bulk scope written to the fallback alone, so values deleted
     * or changed during the outage cannot reappear. Throws (leaving the record
     * intact) if the primary is still unreachable.
     */
    private function reconcile(): void
    {
        if (!$this->clearPrimaryOnRecovery && $this->outageKeys === [] && $this->outageBulk === []) {
            return;
        }

        $start = microtime(true);
        $invalidated = $this->clearPrimaryOnRecovery ? null : count($this->outageKeys) + count($this->outageBulk);

        if ($this->clearPrimaryOnRecovery) {
            $this->primary->clear();
        } else {
            foreach ($this->outageKeys as $key) {
                $this->primary->delete($key);
            }

            foreach ($this->outageBulk as [$kind, $target]) {
                if ($kind === 'scope') {
                    $this->scopeFlushable($this->primary)->clearScope($target);
                } else {
                    $this->taggable($this->primary)->clearTag($target);
                }
            }
        }

        $this->clearPrimaryOnRecovery = false;
        $this->outageKeys = [];
        $this->outageBulk = [];

        SafeDispatch::to($this->events, CacheEvent::reconciled(
            'ResilientStore',
            (microtime(true) - $start) * 1_000_000,
            $invalidated,
        )->withContext(operation: $invalidated === null ? 'clear' : 'invalidate'));
    }

    private function primarySucceeded(): void
    {
        $this->breaker->recordSuccess();
        $this->reportOutageState();
    }

    private function primaryFailed(Throwable $error): void
    {
        $this->breaker->recordFailure();
        $this->reportOutageState($error);
    }

    /**
     * Report the start of an outage when the circuit first opens, and its end
     * when the primary serves again.
     *
     * @param ?Throwable $cause the failure just recorded, if any
     */
    private function reportOutageState(?Throwable $cause = null): void
    {
        $state = $this->breaker->state();

        if (!$this->outageReported && $state === CircuitBreaker::OPEN && $cause !== null) {
            $this->outageReported = true;
            SafeDispatch::to($this->events, CacheEvent::circuitOpened('ResilientStore', $cause));
        } elseif ($this->outageReported && $state === CircuitBreaker::CLOSED) {
            $this->outageReported = false;
            SafeDispatch::to($this->events, CacheEvent::circuitClosed('ResilientStore'));
        }
    }

    /**
     * @param string $name
     * @param list<Key> $keys
     * @param int $count
     * @param ?Scope $scope the scope of a scoped clear, when that was the operation
     */
    private function reportFallback(string $name, array $keys, int $count, ?Scope $scope = null): void
    {
        $key = count($keys) === 1 ? $keys[0] : null;
        $scope ??= $key?->scope();

        SafeDispatch::to($this->events, CacheEvent::fallbackServed(
            'ResilientStore',
            $key === null ? null : (string) $key,
            $count,
        )->withContext($scope === null ? null : (string) $scope, operation: $name));
    }

    /**
     * @param iterable<mixed> $items
     * @return list<mixed>
     */
    private function materialize(iterable $items): array
    {
        return is_array($items) ? array_values($items) : iterator_to_array($items, false);
    }

    /**
     * @param Store $store
     * @return BatchStore
     */
    private function batch(Store $store): BatchStore
    {
        return Capabilities::require($store, BatchStore::class, 'batch');
    }

    /**
     * @param Store $store
     * @return TouchStore
     */
    private function touchable(Store $store): TouchStore
    {
        return Capabilities::require($store, TouchStore::class, 'touch');
    }

    /**
     * @param Store $store
     * @return PrunableStore
     */
    private function prunable(Store $store): PrunableStore
    {
        return Capabilities::require($store, PrunableStore::class, 'prune');
    }

    /**
     * @param Store $store
     * @return InspectableStore
     */
    private function inspectable(Store $store): InspectableStore
    {
        return Capabilities::require($store, InspectableStore::class, 'entries');
    }

    /**
     * @param Store $store
     * @return FlushableScopeStore
     */
    private function scopeFlushable(Store $store): FlushableScopeStore
    {
        return Capabilities::require($store, FlushableScopeStore::class, 'clearScope');
    }

    /**
     * @param Store $store
     * @return TaggableStore
     */
    private function taggable(Store $store): TaggableStore
    {
        return Capabilities::require($store, TaggableStore::class, 'tag');
    }

    /**
     * @param Store $store
     * @return AtomicStore
     */
    private function atomic(Store $store): AtomicStore
    {
        return Capabilities::require($store, AtomicStore::class, 'increment');
    }

    /**
     * @param Store $store
     * @return LockingStore
     */
    private function lockable(Store $store): LockingStore
    {
        return Capabilities::require($store, LockingStore::class, 'lock');
    }
}
