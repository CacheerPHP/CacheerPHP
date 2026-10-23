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
use Silviooosilva\CacheerPhp\Observability\SafeDispatch;
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

    private ?Scope $lastScope = null;

    private string $lastScopePath = '';

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
    /**
     * The wrapped store's name, as it appears in this store's events.
     *
     * @return string
     */
    public function name(): string
    {
        return $this->name;
    }

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
        $entry = $this->guard('get', $key, fn (): CacheEntry => $this->inner->get($key));
        $duration = $this->elapsed($start);

        if ($entry->isHit()) {
            [$bytes, $hasValue, $value] = $this->capture($entry->value());
            SafeDispatch::to($this->events, CacheEvent::hit($this->name, (string) $key, $duration, $bytes, $hasValue, $value, $this->scopePath($key->scope()), 'get'));
        } else {
            SafeDispatch::to($this->events, CacheEvent::miss($this->name, (string) $key, $duration, $this->scopePath($key->scope()), 'get'));
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
        $this->guard('set', $key, function () use ($key, $value, $ttl): void {
            $this->inner->set($key, $value, $ttl);
        });

        [$bytes, $hasValue, $captured] = $this->capture($value);
        SafeDispatch::to($this->events, CacheEvent::written($this->name, (string) $key, $this->elapsed($start), $bytes, $hasValue, $captured, scope: $this->scopePath($key->scope()), ttl: $ttl, operation: 'set'));
    }

    /**
     * @param Key $key
     * @return bool
     */
    public function delete(Key $key): bool
    {
        $start = microtime(true);
        $existed = $this->guard('delete', $key, fn (): bool => $this->inner->delete($key));
        SafeDispatch::to($this->events, CacheEvent::deleted($this->name, (string) $key, $this->elapsed($start), $existed, $this->scopePath($key->scope()), 'delete'));

        return $existed;
    }

    public function clear(): void
    {
        $start = microtime(true);
        $this->guard('clear', null, function (): void {
            $this->inner->clear();
        });
        $this->emit(CacheEvent::cleared($this->name, $this->elapsed($start)), 'clear', scope: Scope::root());
    }

    /**
     * @param iterable<Key> $keys
     * @return list<CacheEntry>
     */
    public function getMany(iterable $keys): array
    {
        $batch = Capabilities::as($this->inner, BatchStore::class);
        if ($batch === null) {
            $entries = [];
            foreach ($keys as $key) {
                $entries[] = $this->get($key);
            }

            return $entries;
        }

        // Forward to the native batch so monitoring keeps its behavior; the
        // per-key events follow, sharing the batch's duration.
        $keys = [...$keys];
        $start = microtime(true);
        $entries = $this->guard('getMany', null, fn (): array => $batch->getMany($keys));
        $duration = $this->elapsed($start) / max(1, count($entries));

        foreach ($entries as $entry) {
            if ($entry->isHit()) {
                [$bytes, $hasValue, $value] = $this->capture($entry->value());
                SafeDispatch::to($this->events, CacheEvent::hit($this->name, (string) $entry->key(), $duration, $bytes, $hasValue, $value, $this->scopePath($entry->key()->scope()), 'getMany'));
            } else {
                SafeDispatch::to($this->events, CacheEvent::miss($this->name, (string) $entry->key(), $duration, $this->scopePath($entry->key()->scope()), 'getMany'));
            }
        }

        return $entries;
    }

    /**
     * @param iterable $entries
     * @param Ttl $ttl
     */
    public function setMany(iterable $entries, Ttl $ttl): void
    {
        $batch = Capabilities::as($this->inner, BatchStore::class);
        if ($batch === null) {
            foreach ($entries as $entry) {
                $this->set($entry['key'], $entry['value'], $ttl);
            }

            return;
        }

        // The native batch keeps its atomicity (e.g. a database transaction):
        // a failing entry rolls back the whole batch, exactly as unmonitored.
        $entries = [...$entries];
        $start = microtime(true);
        $this->guard('setMany', null, function () use ($batch, $entries, $ttl): void {
            $batch->setMany($entries, $ttl);
        });
        $duration = $this->elapsed($start) / max(1, count($entries));

        foreach ($entries as $entry) {
            [$bytes, $hasValue, $captured] = $this->capture($entry['value']);
            SafeDispatch::to($this->events, CacheEvent::written($this->name, (string) $entry['key'], $duration, $bytes, $hasValue, $captured, scope: $this->scopePath($entry['key']->scope()), ttl: $ttl, operation: 'setMany'));
        }
    }

    /**
     * @param iterable<Key> $keys
     * @return bool
     */
    public function deleteMany(iterable $keys): bool
    {
        $batch = Capabilities::as($this->inner, BatchStore::class);
        if ($batch === null) {
            $deleted = true;
            foreach ($keys as $key) {
                $deleted = $this->delete($key) && $deleted;
            }

            return $deleted;
        }

        $keys = [...$keys];
        $start = microtime(true);
        $deleted = $this->guard('deleteMany', null, fn (): bool => $batch->deleteMany($keys));
        $duration = $this->elapsed($start) / max(1, count($keys));

        // A native batch reports one result for all keys, so each delete event
        // carries that result rather than a per-key existence check.
        foreach ($keys as $key) {
            SafeDispatch::to($this->events, CacheEvent::deleted($this->name, (string) $key, $duration, $deleted, $this->scopePath($key->scope()), 'deleteMany'));
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
        $touched = $this->guard('touch', $key, fn (): bool => $this->touchable()->touch($key, $ttl));

        // Reported as a write: no value changed, but the entry did. Without this
        // a renewed TTL is invisible to telemetry. The operation tells it apart.
        if ($touched) {
            $this->emit(CacheEvent::written($this->name, (string) $key, $this->elapsed($start)), 'touch', $key, $ttl);
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
        $this->emit(CacheEvent::pruned($this->name, $this->elapsed($start), $removed), 'prune');

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
        $this->emit(CacheEvent::cleared($this->name, $this->elapsed($start)), 'clearScope', scope: $scope);
    }

    /**
     * @param Key $key
     * @param string ...$tags
     */
    public function tag(Key $key, string ...$tags): void
    {
        $start = microtime(true);
        $this->guard('tag', $key, function () use ($key, $tags): void {
            $this->taggable()->tag($key, ...$tags);
        });

        $this->emit(CacheEvent::written(
            $this->name,
            (string) $key,
            $this->elapsed($start),
            count: count($tags),
        ), 'tag', $key);
    }

    /**
     * @param string $tag
     * @return int
     */
    public function clearTag(string $tag): int
    {
        $start = microtime(true);
        $removed = $this->guard('clearTag', null, fn (): int => $this->taggable()->clearTag($tag));

        // A tag flush is a bulk invalidation, so it reports as a prune carrying
        // how many entries went with it; the operation tells it apart.
        $this->emit(CacheEvent::pruned($this->name, $this->elapsed($start), $removed), 'clearTag');

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
        $value = $this->guard('increment', $key, fn (): int => $this->atomic()->increment($key, $amount, $initial, $ttl));

        // Counters are writes; the new value rides along in `count` so a
        // dashboard can chart it without value capture being enabled.
        $this->emit(CacheEvent::written(
            $this->name,
            (string) $key,
            $this->elapsed($start),
            count: $value,
        ), 'increment', $key, $ttl);

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
        $swapped = $this->guard('compareAndSwap', $key, fn (): bool => $this->atomic()->compareAndSwap($key, $expected, $value, $ttl));

        // Only a successful swap wrote anything.
        if ($swapped) {
            [$bytes, $hasValue, $captured] = $this->capture($value);
            $this->emit(CacheEvent::written(
                $this->name,
                (string) $key,
                $this->elapsed($start),
                $bytes,
                $hasValue,
                $captured,
            ), 'compareAndSwap', $key, $ttl);
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
     * @param string $operation
     * @param ?Key $key
     * @param callable(): T $operationFn
     * @return T
     */
    private function guard(string $operation, ?Key $key, callable $operationFn): mixed
    {
        $start = microtime(true);

        try {
            return $operationFn();
        } catch (Throwable $exception) {
            $this->emit(CacheEvent::failed($this->name, $key === null ? null : (string) $key, $this->elapsed($start), $exception), $operation, $key);

            throw $exception;
        }
    }

    /**
     * Dispatch an event with its context: the operation, the scope of the key
     * (or of a scoped clear), and the TTL a write set. A TTL the caller left to
     * the store (a counter's null TTL) stays unreported rather than guessed.
     *
     * @param CacheEvent $event
     * @param string $operation
     * @param ?Key $key
     * @param ?Ttl $ttl
     * @param ?Scope $scope
     */
    private function emit(CacheEvent $event, string $operation, ?Key $key = null, ?Ttl $ttl = null, ?Scope $scope = null): void
    {
        $scope ??= $key?->scope();

        SafeDispatch::to($this->events, $event->withContext(
            $scope === null ? null : $this->scopePath($scope),
            $ttl,
            $operation,
        ));
    }

    /**
     * A cache passes the same Scope instance with every key, so the path of the
     * last one is kept rather than rebuilt for each event.
     *
     * @param Scope $scope
     * @return string
     */
    private function scopePath(Scope $scope): string
    {
        if ($scope !== $this->lastScope) {
            $this->lastScope = $scope;
            $this->lastScopePath = (string) $scope;
        }

        return $this->lastScopePath;
    }

    /**
     * @param mixed $value
     * @return array{0: int|null, 1: bool, 2: mixed}
     */
    private function capture(mixed $value): array
    {
        $bytes = self::serializedSize($value, $this->captureValues);

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
    /**
     * The serialized size of a value, without serializing anything costly.
     *
     * Events are emitted on every read and write, and with Monitor installed
     * every cache is instrumented, so sizing must stay cheap: a string's
     * serialized size is computed arithmetically, other scalars are trivial to
     * serialize, and arrays/objects are reported as unknown (null) — sizing them
     * would mean serializing the whole value on every hit. Value capture is an
     * explicit debugging mode, so it pays that cost to report the size.
     *
     * @param mixed $value
     * @param bool $sizeStructured
     * @return ?int
     */
    private static function serializedSize(mixed $value, bool $sizeStructured): ?int
    {
        if (is_string($value)) {
            $length = strlen($value);

            return $length + strlen((string) $length) + 6; // s:<length>:"<value>";
        }

        if (is_scalar($value) || $value === null) {
            return strlen(serialize($value));
        }

        if (!$sizeStructured) {
            return null;
        }

        try {
            return strlen(serialize($value));
        } catch (Throwable) {
            return null;
        }
    }

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
