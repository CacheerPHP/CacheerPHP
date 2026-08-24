<?php

declare(strict_types=1);

namespace Silviooosilva\CacheerPhp\Core;

use DateInterval;
use InvalidArgumentException;
use Silviooosilva\CacheerPhp\Config\CachePolicy;
use Silviooosilva\CacheerPhp\Contracts\AtomicStore;
use Silviooosilva\CacheerPhp\Contracts\BatchStore;
use Silviooosilva\CacheerPhp\Contracts\Clock;
use Silviooosilva\CacheerPhp\Contracts\DeferredExecutor;
use Silviooosilva\CacheerPhp\Contracts\EventDispatcher;
use Silviooosilva\CacheerPhp\Contracts\FlushableScopeStore;
use Silviooosilva\CacheerPhp\Contracts\InspectableStore;
use Silviooosilva\CacheerPhp\Contracts\Lock;
use Silviooosilva\CacheerPhp\Contracts\LockingStore;
use Silviooosilva\CacheerPhp\Contracts\PrunableStore;
use Silviooosilva\CacheerPhp\Contracts\Store;
use Silviooosilva\CacheerPhp\Contracts\TaggableStore;
use Silviooosilva\CacheerPhp\Contracts\TouchStore;
use Silviooosilva\CacheerPhp\Exceptions\CacheException;
use Silviooosilva\CacheerPhp\Exceptions\InvalidKeyException;
use Silviooosilva\CacheerPhp\Exceptions\StoreOperationFailedException;
use Silviooosilva\CacheerPhp\Exceptions\UnsupportedCapabilityException;
use Silviooosilva\CacheerPhp\Kernel\CacheEntry;
use Silviooosilva\CacheerPhp\Kernel\Capabilities;
use Silviooosilva\CacheerPhp\Kernel\Key;
use Silviooosilva\CacheerPhp\Kernel\Scope;
use Silviooosilva\CacheerPhp\Kernel\Ttl;
use Silviooosilva\CacheerPhp\Observability\CacheEvent;
use Silviooosilva\CacheerPhp\Observability\NullEventDispatcher;
use Silviooosilva\CacheerPhp\Support\SyncDeferredExecutor;
use Silviooosilva\CacheerPhp\Support\SystemClock;
use Throwable;
use UnexpectedValueException;

/**
 * The cache engine: everything Cacheer does, minus the fluent surface.
 *
 * Holds the scope and the optional policy, so both apply uniformly — including
 * to the capability operations, whose keys are scoped here rather than being
 * built by the caller against the raw store.
 *
 * @internal
 */
final readonly class CacheOperations
{
    public function __construct(
        private Store $store,
        private Scope $scope,
        private Clock $clock = new SystemClock(),
        private DeferredExecutor $executor = new SyncDeferredExecutor(),
        private EventDispatcher $events = new NullEventDispatcher(),
        private ?CachePolicy $policy = null,
    ) {
    }

    public function store(): Store
    {
        return $this->store;
    }

    public function scope(): Scope
    {
        return $this->scope;
    }

    public function policy(): ?CachePolicy
    {
        return $this->policy;
    }

    // ------------------------------------------------------------------ read --

    public function entry(string|Key $key): CacheEntry
    {
        $key = $this->key($key);

        return $this->run('get', $key, fn (): CacheEntry => $this->store->get($key));
    }

    public function get(string|Key $key, mixed $default = null): mixed
    {
        return $this->entry($key)->valueOr($default);
    }

    public function has(string|Key $key): bool
    {
        return $this->entry($key)->isHit();
    }

    /**
     * @param iterable<string|Key> $keys
     * @return array<string, mixed>
     */
    public function many(iterable $keys, mixed $default = null): array
    {
        $normalized = [];

        foreach ($keys as $key) {
            $normalized[] = $this->key($key);
        }

        $batch = Capabilities::as($this->store, BatchStore::class);

        if ($batch !== null) {
            $entries = $this->run('getMany', null, fn (): array => $batch->getMany($normalized));

            if (count($entries) !== count($normalized)) {
                throw new StoreOperationFailedException(
                    'getMany',
                    null,
                    new UnexpectedValueException('A BatchStore must return one entry for every requested key.'),
                );
            }
        } else {
            $entries = array_map(
                fn (Key $key): CacheEntry => $this->run('get', $key, fn (): CacheEntry => $this->store->get($key)),
                $normalized,
            );
        }

        $values = [];
        foreach ($entries as $index => $entry) {
            $values[$normalized[$index]->value()] = $entry->valueOr($default);
        }

        return $values;
    }

    // ----------------------------------------------------------------- write --

    public function set(
        string|Key $key,
        mixed $value,
        Ttl|DateInterval|int|string|null $ttl = null,
    ): void {
        $this->put($this->key($key), $value, $this->ttl($ttl, $value));
    }

    public function add(
        string|Key $key,
        mixed $value,
        Ttl|DateInterval|int|string|null $ttl = null,
    ): bool {
        $key = $this->key($key);
        $resolved = $this->ttl($ttl, $value);
        $lock = $this->tryLock('cacheer:add:', $key);

        if ($lock === null || !$lock->block(5.0)) {
            return $this->addUnlocked($key, $value, $resolved);
        }

        try {
            return $this->addUnlocked($key, $value, $resolved);
        } finally {
            $lock->release();
        }
    }

    public function delete(string|Key $key): bool
    {
        $key = $this->key($key);

        return $this->run('delete', $key, fn (): bool => $this->store->delete($key));
    }

    public function pull(string|Key $key, mixed $default = null): mixed
    {
        $key = $this->key($key);
        $entry = $this->entry($key);

        if ($entry->isMiss()) {
            return $default;
        }

        $this->delete($key);

        return $entry->value();
    }

    /**
     * @param iterable<array-key, mixed> $values
     */
    public function setMany(
        iterable $values,
        Ttl|DateInterval|int|string|null $ttl = null,
    ): void {
        $entries = [];

        foreach ($values as $key => $value) {
            if (!is_string($key)) {
                throw new InvalidKeyException('setMany() requires string keys.');
            }

            $entries[] = ['key' => $this->key($key), 'value' => $value];
        }

        // A policy can resolve a different TTL per value (negative caching), so a
        // batch write is only sound when every entry lands on the same TTL.
        if ($this->policy !== null) {
            foreach ($entries as $entry) {
                $this->put($entry['key'], $entry['value'], $this->ttl($ttl, $entry['value']));
            }

            return;
        }

        $resolved = Ttl::from($ttl);
        $batch = Capabilities::as($this->store, BatchStore::class);

        if ($batch !== null) {
            $this->run('setMany', null, function () use ($batch, $entries, $resolved): void {
                $batch->setMany($entries, $resolved);
            });

            return;
        }

        foreach ($entries as $entry) {
            $this->put($entry['key'], $entry['value'], $resolved);
        }
    }

    /**
     * @param iterable<string|Key> $keys
     */
    public function deleteMany(iterable $keys): bool
    {
        $normalized = [];

        foreach ($keys as $key) {
            $normalized[] = $this->key($key);
        }

        $batch = Capabilities::as($this->store, BatchStore::class);

        if ($batch !== null) {
            return $this->run('deleteMany', null, fn (): bool => $batch->deleteMany($normalized));
        }

        $deleted = true;
        foreach ($normalized as $key) {
            $deleted = $this->run('delete', $key, fn (): bool => $this->store->delete($key)) && $deleted;
        }

        return $deleted;
    }

    public function clear(): void
    {
        if ($this->scope->isRoot()) {
            $this->run('clear', null, function (): void {
                $this->store->clear();
            });

            return;
        }

        $flushable = Capabilities::require($this->store, FlushableScopeStore::class, 'clear');

        $this->run('clearScope', null, function () use ($flushable): void {
            $flushable->clearScope($this->scope);
        });
    }

    // --------------------------------------------------------------- compute --

    public function remember(
        string|Key $key,
        Ttl|DateInterval|int|string|null $ttl,
        callable $callback,
    ): mixed {
        $key = $this->key($key);

        if ($this->policy?->servesStaleOnError() === true) {
            return $this->rememberServingStale($key, $ttl, $callback);
        }

        $entry = $this->entry($key);
        if ($entry->isHit()) {
            return $entry->value();
        }

        return $this->singleFlight($key, $ttl, $callback);
    }

    /**
     * Stale-while-revalidate: within $fresh seconds a value is served directly;
     * between $fresh and $stale the stale value is served while a single worker
     * refreshes it (deferred via the executor); past $stale it is recomputed
     * synchronously. A cached value must still be present, so this composes with
     * a hard TTL of $stale.
     */
    public function flexible(string|Key $key, int $fresh, int $stale, callable $callback): mixed
    {
        if ($fresh < 1 || $stale <= $fresh) {
            throw new InvalidArgumentException('flexible() requires 0 < fresh < stale.');
        }

        $key = $this->key($key);
        $entry = $this->entry($key);

        if ($entry->isHit()) {
            $freshUntil = ($entry->createdAt() ?? $this->clock->now()) + $fresh;
            if ($this->clock->now() < $freshUntil) {
                return $entry->value();
            }

            $this->events->dispatch(CacheEvent::staleServed($this->storeName(), (string) $key));
            $this->scheduleRefresh($key, $stale, $callback);

            return $entry->value();
        }

        // The stale window is an explicit contract from the caller, so a bound
        // policy's jitter and negative TTL must not reshape it.
        return $this->singleFlight($key, Ttl::seconds($stale), $callback, applyPolicy: false);
    }

    // ---------------------------------------------------------- capabilities --

    /**
     * @param class-string $capability
     */
    public function supports(string $capability): bool
    {
        return Capabilities::supports($this->store, $capability);
    }

    public function increment(
        string|Key $key,
        int $amount = 1,
        ?int $initial = null,
        Ttl|DateInterval|int|string|null $ttl = null,
    ): int {
        $key = $this->key($key);
        $atomic = Capabilities::require($this->store, AtomicStore::class, 'increment');
        $resolved = $ttl === null ? null : Ttl::from($ttl);

        return $this->run(
            'increment',
            $key,
            fn (): int => $atomic->increment($key, $amount, $initial, $resolved),
        );
    }

    public function touch(string|Key $key, Ttl|DateInterval|int|string $ttl): bool
    {
        $key = $this->key($key);
        $touchable = Capabilities::require($this->store, TouchStore::class, 'touch');
        $resolved = Ttl::from($ttl);

        return $this->run('touch', $key, fn (): bool => $touchable->touch($key, $resolved));
    }

    public function tag(string|Key $key, string ...$tags): void
    {
        $key = $this->key($key);
        $taggable = Capabilities::require($this->store, TaggableStore::class, 'tag');

        $qualified = array_map($this->qualifiedTag(...), $tags);

        $this->run('tag', $key, function () use ($taggable, $key, $qualified): void {
            $taggable->tag($key, ...$qualified);
        });
    }

    public function flushTag(string $tag): int
    {
        $taggable = Capabilities::require($this->store, TaggableStore::class, 'flushTag');

        return $this->run('flushTag', null, fn (): int => $taggable->clearTag($this->qualifiedTag($tag)));
    }

    public function lock(string $name, Ttl|DateInterval|int|string $ttl = 60): Lock
    {
        $locking = Capabilities::require($this->store, LockingStore::class, 'lock');

        return $locking->lock($this->qualifiedLockName($name), Ttl::from($ttl));
    }

    /**
     * @return iterable<CacheEntry>
     */
    public function entries(): iterable
    {
        $inspectable = Capabilities::require($this->store, InspectableStore::class, 'entries');

        return $inspectable->entries($this->scope->isRoot() ? null : $this->scope);
    }

    public function prune(): int
    {
        $prunable = Capabilities::require($this->store, PrunableStore::class, 'prune');

        return $this->run('prune', null, fn (): int => $prunable->prune());
    }

    /**
     * @return array{store: string, scope: string, policy: bool, capabilities: array<string, bool>}
     */
    public function stats(): array
    {
        $capabilities = [];

        foreach ([
            'batch'      => BatchStore::class,
            'touch'      => TouchStore::class,
            'prune'      => PrunableStore::class,
            'inspect'    => InspectableStore::class,
            'scopeFlush' => FlushableScopeStore::class,
            'tags'       => TaggableStore::class,
            'atomic'     => AtomicStore::class,
            'locking'    => LockingStore::class,
        ] as $label => $capability) {
            $capabilities[$label] = Capabilities::supports($this->store, $capability);
        }

        return [
            'store'        => $this->storeName(),
            'scope'        => (string) $this->scope,
            'policy'       => $this->policy !== null,
            'capabilities' => $capabilities,
        ];
    }

    public function nestedScope(string|Scope $scope): Scope
    {
        $scope = is_string($scope) ? Scope::named($scope) : $scope;

        return $this->scope->append($scope);
    }

    // -------------------------------------------------------------- internals --

    private function key(string|Key $key): Key
    {
        $key = is_string($key) ? Key::named($key) : $key;

        return $key->within($this->scope);
    }

    /**
     * Tags and lock names are keyspaces of their own, so a scoped cache must not
     * collide with another scope's tag or lock of the same name.
     */
    private function qualifiedTag(string $tag): string
    {
        return $this->scope->isRoot() ? $tag : $this->scope->identity() . '|' . $tag;
    }

    private function qualifiedLockName(string $name): string
    {
        return $this->scope->isRoot() ? $name : $this->scope->identity() . '|' . $name;
    }

    /**
     * The effective write TTL: what the caller asked for, run through the policy
     * when one is bound.
     */
    private function ttl(Ttl|DateInterval|int|string|null $ttl, mixed $value): Ttl
    {
        if ($this->policy === null) {
            return Ttl::from($ttl);
        }

        return $this->policy->resolveTtl($ttl instanceof DateInterval ? Ttl::from($ttl) : $ttl, $value);
    }

    private function put(Key $key, mixed $value, Ttl $ttl): void
    {
        $this->run('set', $key, function () use ($key, $value, $ttl): void {
            $this->store->set($key, $value, $ttl);
        });
    }

    private function addUnlocked(Key $key, mixed $value, Ttl $ttl): bool
    {
        if ($this->entry($key)->isHit()) {
            return false;
        }

        $this->put($key, $value, $ttl);

        return true;
    }

    /**
     * remember() under a serve-stale-on-error policy: inside the grace window
     * after logical expiry a failing callback yields the last good value rather
     * than propagating. The entry is written with the grace added to its TTL, so
     * it is still readable while stale.
     *
     * @param callable(): mixed $callback
     */
    private function rememberServingStale(
        Key $key,
        Ttl|DateInterval|int|string|null $ttl,
        callable $callback,
    ): mixed {
        $grace = $this->policy?->graceSeconds() ?? 0;
        $entry = $this->entry($key);

        if ($entry->isHit()) {
            $remaining = $entry->remainingTtl($this->clock);
            if ($remaining === null || $remaining > $grace) {
                return $entry->value();
            }

            try {
                $value = $callback();
            } catch (Throwable) {
                return $entry->value();
            }

            $this->put($key, $value, $this->hardTtl($ttl, $value, $grace));

            return $value;
        }

        $value = $callback();
        $this->put($key, $value, $this->hardTtl($ttl, $value, $grace));

        return $value;
    }

    private function hardTtl(Ttl|DateInterval|int|string|null $ttl, mixed $value, int $grace): Ttl
    {
        $base = $this->ttl($ttl, $value);
        $seconds = $base->inSeconds();

        return $seconds === null ? $base : Ttl::seconds($seconds + $grace);
    }

    /**
     * Compute-and-store a value at most once across concurrent callers. When the
     * store can lock, one caller computes while the rest wait and read the
     * result; otherwise it degrades to a plain compute-and-store.
     *
     * @param callable(): mixed $callback
     */
    private function singleFlight(
        Key $key,
        Ttl|DateInterval|int|string|null $ttl,
        callable $callback,
        bool $applyPolicy = true,
    ): mixed {
        $lock = $this->tryLock('cacheer:sf:', $key);

        if ($lock === null || !$lock->block(5.0)) {
            if ($lock !== null) {
                $this->events->dispatch(CacheEvent::lockContended($this->storeName(), (string) $key));
            }

            return $this->compute($key, $ttl, $callback, $applyPolicy);
        }

        try {
            $entry = $this->entry($key);
            if ($entry->isHit()) {
                return $entry->value();
            }

            return $this->compute($key, $ttl, $callback, $applyPolicy);
        } finally {
            $lock->release();
        }
    }

    /**
     * @param callable(): mixed $callback
     */
    private function compute(
        Key $key,
        Ttl|DateInterval|int|string|null $ttl,
        callable $callback,
        bool $applyPolicy = true,
    ): mixed {
        $value = $callback();
        $this->put($key, $value, $applyPolicy ? $this->ttl($ttl, $value) : Ttl::from($ttl));

        return $value;
    }

    /**
     * @param callable(): mixed $callback
     */
    private function scheduleRefresh(Key $key, int $stale, callable $callback): void
    {
        $this->executor->defer(function () use ($key, $stale, $callback): void {
            $lock = $this->tryLock('cacheer:swr:', $key);

            if ($lock === null) {
                $this->compute($key, Ttl::seconds($stale), $callback, applyPolicy: false);
                $this->events->dispatch(CacheEvent::refreshed($this->storeName(), (string) $key));

                return;
            }

            if (!$lock->acquire()) {
                return;
            }

            try {
                $this->compute($key, Ttl::seconds($stale), $callback, applyPolicy: false);
                $this->events->dispatch(CacheEvent::refreshed($this->storeName(), (string) $key));
            } finally {
                $lock->release();
            }
        });
    }

    /**
     * A lock for stampede control, or null when this store cannot lock.
     *
     * Locking is an optimization here, never a requirement: every caller falls
     * back to an unlocked path. A store that cannot lock — or a decorator whose
     * wrapped store cannot — must therefore degrade, not fail, which is why this
     * asks {@see Capabilities} instead of using `instanceof`, and still absorbs
     * an UnsupportedCapabilityException from a store that misreports.
     */
    private function tryLock(string $prefix, Key $key): ?Lock
    {
        $store = Capabilities::as($this->store, LockingStore::class);

        if ($store === null) {
            return null;
        }

        try {
            return $store->lock($prefix . hash('sha256', $key->identity()), Ttl::seconds(30));
        } catch (UnsupportedCapabilityException) {
            return null;
        }
    }

    private function storeName(): string
    {
        return (new \ReflectionClass($this->store))->getShortName();
    }

    /**
     * @template T
     * @param callable(): T $operation
     * @return T
     */
    private function run(string $name, ?Key $key, callable $operation): mixed
    {
        try {
            return $operation();
        } catch (CacheException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new StoreOperationFailedException($name, $key, $exception);
        }
    }
}
