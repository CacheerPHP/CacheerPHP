<?php

declare(strict_types=1);

namespace Silviooosilva\CacheerPhp\Compat;

use Closure;
use DateInterval;
use Silviooosilva\CacheerPhp\Contracts\AtomicStore;
use Silviooosilva\CacheerPhp\Contracts\Clock;
use Silviooosilva\CacheerPhp\Contracts\Store;
use Silviooosilva\CacheerPhp\Contracts\TaggableStore;
use Silviooosilva\CacheerPhp\Exceptions\UnsupportedCapabilityException;
use Silviooosilva\CacheerPhp\Kernel\Cache;
use Silviooosilva\CacheerPhp\Kernel\Key;
use Silviooosilva\CacheerPhp\Kernel\Scope;
use Silviooosilva\CacheerPhp\Kernel\Ttl;
use Silviooosilva\CacheerPhp\Stores\ArrayStore;
use Silviooosilva\CacheerPhp\Stores\FileStore;
use Silviooosilva\CacheerPhp\Support\SystemClock;

/**
 * v5 compatibility bridge over the instance-first v6 kernel.
 *
 * This class re-exposes the v5 method surface (putCache/getCache/clearCache/…)
 * so an application can adopt the v6 engine without rewriting call sites in a
 * single step. It translates the positional namespace into a v6 scope, drops the
 * removed read-time TTL, and reports success through isSuccess()/getMessage() the
 * way v5 code expects.
 *
 * Deprecation notices are opt-in. Production defaults stay silent; enable them in
 * development to find call sites that still use the legacy API:
 *
 *     $legacy = LegacyCacheer::file('/var/cache', emitDeprecations: true);
 *
 * The bridge is a migration aid, not part of the v6 core. New code should depend
 * on {@see Cache} directly.
 */
final class LegacyCacheer
{
    private readonly Cache $cache;

    private bool $success = false;

    private string $message = '';

    public function __construct(
        private readonly Store $store,
        private readonly Clock $clock = new SystemClock(),
        private readonly bool $emitDeprecations = false,
    ) {
        $this->cache = new Cache($store, $clock);
    }

    /**
     * Bridge backed by the in-process array store (tests, short CLI runs).
     */
    public static function inMemory(bool $emitDeprecations = false): self
    {
        $clock = new SystemClock();

        return new self(new ArrayStore($clock), $clock, $emitDeprecations);
    }

    /**
     * Bridge backed by the filesystem store — the closest analogue to v5's
     * default file driver.
     */
    public static function file(string $directory, bool $emitDeprecations = false): self
    {
        $clock = new SystemClock();

        return new self(new FileStore($directory, clock: $clock), $clock, $emitDeprecations);
    }

    public function putCache(string $cacheKey, mixed $cacheData, string $namespace = '', int|string|DateInterval|null $ttl = 3600): bool
    {
        $this->deprecate('putCache', 'Cache::set()');

        $this->scoped($namespace)->set($cacheKey, $cacheData, $ttl);

        return $this->succeed('Cache stored successfully.');
    }

    public function forever(string $cacheKey, mixed $cacheData): bool
    {
        $this->deprecate('forever', 'Cache::set() with a null (forever) TTL');

        $this->cache->set($cacheKey, $cacheData, Ttl::forever());

        return $this->succeed('Cache stored successfully.');
    }

    public function getCache(string $cacheKey, string $namespace = '', mixed $default = null): mixed
    {
        $this->deprecate('getCache', 'Cache::get()');

        $entry = $this->scoped($namespace)->entry($cacheKey);

        if ($entry->isHit()) {
            $this->succeed('Cache retrieved successfully.');

            return $entry->value();
        }

        $this->fail('cacheData not found, does not exists or expired.');

        return $default;
    }

    public function clearCache(string $cacheKey, string $namespace = ''): bool
    {
        $this->deprecate('clearCache', 'Cache::delete()');

        $this->scoped($namespace)->delete($cacheKey);

        return $this->succeed('Cache cleared successfully.');
    }

    /**
     * v5 alias for clearCache().
     */
    public function forget(string $cacheKey, string $namespace = ''): bool
    {
        return $this->clearCache($cacheKey, $namespace);
    }

    public function flushCache(): bool
    {
        $this->deprecate('flushCache', 'Cache::clear()');

        $this->cache->clear();

        return $this->succeed('Cache flushed successfully.');
    }

    public function has(string $cacheKey, string $namespace = ''): bool
    {
        $this->deprecate('has', 'Cache::has()');

        return $this->scoped($namespace)->has($cacheKey);
    }

    public function missing(string $cacheKey, string $namespace = ''): bool
    {
        return !$this->has($cacheKey, $namespace);
    }

    /**
     * Read a value and delete it in one call. v5 exposed this as both
     * getAndForget() and pull().
     */
    public function pull(string $cacheKey, string $namespace = ''): mixed
    {
        $this->deprecate('pull', 'Cache::get() followed by Cache::delete()');

        $cache = $this->scoped($namespace);
        $entry = $cache->entry($cacheKey);

        if ($entry->isMiss()) {
            $this->fail('cacheData not found, does not exists or expired.');

            return null;
        }

        $cache->delete($cacheKey);
        $this->succeed('Cache retrieved successfully.');

        return $entry->value();
    }

    public function getAndForget(string $cacheKey, string $namespace = ''): mixed
    {
        return $this->pull($cacheKey, $namespace);
    }

    public function renewCache(string $cacheKey, int|string|DateInterval|null $ttl = 3600, string $namespace = ''): bool
    {
        $this->deprecate('renewCache', 'Cache::set() with a fresh TTL');

        $cache = $this->scoped($namespace);
        $entry = $cache->entry($cacheKey);

        if ($entry->isMiss()) {
            return $this->fail('cacheData not found, does not exists or expired.');
        }

        $cache->set($cacheKey, $entry->value(), $ttl);

        return $this->succeed('Cache renewed successfully.');
    }

    /**
     * @param Closure(): mixed $callback
     */
    public function remember(string $cacheKey, int|string|DateInterval|null $ttl, Closure $callback, string $namespace = ''): mixed
    {
        $this->deprecate('remember', 'Cache::remember()');

        $value = $this->scoped($namespace)->remember($cacheKey, $ttl, $callback);
        $this->succeed('Cache retrieved successfully.');

        return $value;
    }

    /**
     * @param Closure(): mixed $callback
     */
    public function rememberForever(string $cacheKey, Closure $callback, string $namespace = ''): mixed
    {
        return $this->remember($cacheKey, null, $callback, $namespace);
    }

    /**
     * Increment a counter. Requires an atomic store; returns the new value.
     */
    public function increment(string $cacheKey, int $amount = 1, string $namespace = '', ?int $initial = null, int|string|DateInterval|null $ttl = null): int
    {
        $this->deprecate('increment', 'the AtomicStore capability on your store');

        $store = $this->atomicStore();
        $next = $store->increment($this->key($cacheKey, $namespace), $amount, $initial, $ttl === null ? null : Ttl::from($ttl));
        $this->succeed('Counter incremented successfully.');

        return $next;
    }

    public function decrement(string $cacheKey, int $amount = 1, string $namespace = '', ?int $initial = null, int|string|DateInterval|null $ttl = null): int
    {
        return $this->increment($cacheKey, -$amount, $namespace, $initial, $ttl);
    }

    /**
     * Tag one or more already-stored keys. Requires a taggable store.
     */
    public function tag(string $tag, string ...$keys): bool
    {
        $this->deprecate('tag', 'the TaggableStore capability on your store');

        $store = $this->store;
        if (!$store instanceof TaggableStore) {
            throw UnsupportedCapabilityException::for(TaggableStore::class, 'tag');
        }

        foreach ($keys as $key) {
            $store->tag(Key::named($key), $tag);
        }

        return $this->succeed('Keys tagged successfully.');
    }

    public function flushTag(string $tag): bool
    {
        $this->deprecate('flushTag', 'the TaggableStore capability on your store');

        $store = $this->store;
        if (!$store instanceof TaggableStore) {
            throw UnsupportedCapabilityException::for(TaggableStore::class, 'flushTag');
        }

        $store->clearTag($tag);

        return $this->succeed('Tag flushed successfully.');
    }

    /**
     * Append data to an existing cached value. Arrays are merged, strings are
     * concatenated, and anything else is wrapped into a list.
     */
    public function appendCache(string $cacheKey, mixed $cacheData, string $namespace = ''): bool
    {
        $this->deprecate('appendCache', 'a read-modify-write with Cache::get()/set()');

        $cache = $this->scoped($namespace);
        $entry = $cache->entry($cacheKey);

        if ($entry->isMiss()) {
            return $this->fail('cacheData not found, does not exists or expired.');
        }

        $cache->set($cacheKey, $this->append($entry->value(), $cacheData));

        return $this->succeed('Cache appended successfully.');
    }

    public function isSuccess(): bool
    {
        return $this->success;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    private function append(mixed $current, mixed $addition): mixed
    {
        if (is_array($current)) {
            return is_array($addition) ? array_merge($current, $addition) : [...$current, $addition];
        }

        if (is_string($current) && is_string($addition)) {
            return $current . $addition;
        }

        return [$current, $addition];
    }

    private function atomicStore(): AtomicStore
    {
        if (!$this->store instanceof AtomicStore) {
            throw UnsupportedCapabilityException::for(AtomicStore::class, 'increment');
        }

        return $this->store;
    }

    private function key(string $cacheKey, string $namespace): Key
    {
        $key = Key::named($cacheKey);

        return $namespace === '' ? $key : $key->within(Scope::named($namespace));
    }

    private function scoped(string $namespace): Cache|\Silviooosilva\CacheerPhp\Kernel\ScopedCache
    {
        return $namespace === '' ? $this->cache : $this->cache->scope($namespace);
    }

    private function succeed(string $message): bool
    {
        $this->success = true;
        $this->message = $message;

        return true;
    }

    private function fail(string $message): bool
    {
        $this->success = false;
        $this->message = $message;

        return false;
    }

    private function deprecate(string $old, string $replacement): void
    {
        if ($this->emitDeprecations) {
            @trigger_error(
                sprintf('CacheerPHP: %s() is deprecated in v6; use %s instead.', $old, $replacement),
                E_USER_DEPRECATED,
            );
        }
    }
}
