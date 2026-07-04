# Changelog

All notable changes to CacheerPHP will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [5.2.0] - 2026-06-27

A **fully backwards-compatible** feature release focused on safe concurrency.
Every existing method, signature, and return type continues to work as before;
the new guarantees are automatic and the new parameters are optional.

### Added
- **Distributed locks** — `Cacheer::lock(string $name, int $ttl = 60)` returns a
  `Silviooosilva\CacheerPhp\Support\CacheLock` with:
  - `acquire()` / `release()` (owner-scoped), `block(int $seconds, ?Closure)`,
    `get(?Closure)`, and `owner()`.
  - Available on both an instance and the static facade (`Cacheer::lock(...)`).
  - Backed natively by each driver via the new
    `Silviooosilva\CacheerPhp\Interface\LockProviderInterface`: Redis
    (`SET … NX EX` + compare-and-delete Lua), Database (a `cacheer_locks` table
    whose primary key is the atomic gate), File (`flock(LOCK_EX | LOCK_NB)`),
    and Array (in-process).
- **`flexible()` — stale-while-revalidate**: `flexible(string $key, int $fresh,
  int $stale, Closure $callback, string $namespace = '')`. Serves fresh values
  directly, serves stale values while a single worker refreshes, and recomputes
  once older than `$stale`. Also available via the fluent namespace context.

### Changed
- **`increment()` / `decrement()` are now atomic** — the read-modify-write is
  serialised on a per-key single-flight lock, so concurrent counter updates no
  longer lose increments on lockable drivers (File, Database, Redis). Signatures
  and return values are unchanged.
- **`remember()` / `rememberForever()` are now stampede-safe** — a concurrent
  miss runs the callback once (single-flight) instead of once per request.
  `remember()` gained an optional trailing `string $namespace = ''` parameter,
  and the fluent `in('ns')->remember(...)` path now shares this implementation.
- `composer.json` — `version` set to `5.2.0`.

### Fixed
- **File lock mutual exclusion**: the file lock no longer deletes its lock file
  on release. Unlinking allowed a new acquirer to lock a fresh inode while
  another process still held the old one, briefly admitting two holders into the
  critical section (lost updates under concurrency).
- **Database falsy-value writes**: `CacheDatabaseRepository::store()` decided
  INSERT vs UPDATE with `!empty(retrieve())`, so writing over a stored `0`,
  `false`, or `''` wrongly took the INSERT path and violated the unique
  `(cacheKey, cacheNamespace)` index. It now uses a strict null check.

### Compatibility
- All existing public method signatures and return types — **unchanged**
  (new method parameters are optional).
- Cache file format, database cache schema, Redis key layout — **unchanged**.
  Locks use a separate keyspace (`cacheer_locks` table / `cacheer:lock:*` keys /
  a `cacheer-locks/` directory) and never collide with cached values.
- PSR-16 adapter, encryption, compression — **unchanged**.

## [5.1.0] - 2026-05-07

A **fully backwards-compatible** feature release. Every method, signature, and
return type from v5.0.x continues to work exactly as before. New behaviours are
opt-in.

### Added
- **Convenience aliases** for ergonomic parity with other PHP cache libraries:
  - `forget(string $key, string $namespace = '')` — alias of `clearCache()`.
  - `pull(string $key, string $namespace = '')` — alias of `getAndForget()`.
  - `missing(string $key, string $namespace = '')` — inverse of `has()`.
- **Fluent namespace context** via three new entry points on `Cacheer`:
  - `in(string $namespace)` — short form.
  - `namespace(string $namespace)` — long form (alias of `in()`).
  - `withoutNamespace()` — clears the bound namespace mid-chain.
  All three return an immutable `PendingCache` wrapper. The underlying
  `Cacheer` is never mutated, so this is safe under the static facade.
- **Dot-notation namespaces**: `in('users.123')` is parsed and joined with
  subsequent `in()` calls using `.`. `in('users')->in('123')` is equivalent.
- New `Silviooosilva\CacheerPhp\Support\PendingCache` exposing
  `get`, `getMany`, `put`, `add`, `has`, `missing`, `forget`, `pull`,
  `remember`, `rememberForever`, plus the chain methods `in`, `namespace`,
  `withoutNamespace`, and `getNamespace` / `cacheer` accessors.
- **`putMany()` simple form**: now also accepts a flat associative array
  `['key1' => $v1, 'key2' => $v2]` in addition to the legacy
  `[['cacheKey' => 'k', 'cacheData' => $v]]` shape.
- **`increment()` / `decrement()` enhancements**: two new optional parameters,
  fully backwards-compatible:
  - `?int $default = null` — when the key is missing AND `$default` is given,
    the cache is initialised to `$default + $amount`. With `$default = null`
    (the legacy default) the v5.0.x behaviour is preserved (return `false` on
    miss).
  - `int|string|\DateInterval|null $ttl = null` — TTL applied when writing.

### Changed
- `composer.json` — `version` set to `5.1.0`.

### Compatibility
- Cache file format, database schema, Redis key layout — **unchanged**.
- All existing public method signatures and return types — **unchanged**.
- PSR-16 adapter, encryption, compression — **unchanged**.

## [5.0.0] - 2026-03-09

### Breaking Changes
- PHP 8.2+ now required (was 8.0+)
- `Cacheer::$cacheStore` and `Cacheer::$options` are now **private** — use `getCacheStore()`/`setCacheStore()`, `getOptions()`/`setOption()`/`setOptions()`
- `add()` now returns `true` when key is stored, `false` when key already exists (was inverted in v4)
- `CacheDataFormatter::toJson()` now returns `string` and throws `\JsonException` on failure
- Encryption uses random IV (prepended to ciphertext, base64-encoded) — existing encrypted values are unreadable after upgrade; flush encrypted caches before upgrading
- `FileCacheStore` envelope format changed to `{data, expires_at, ttl}` — flush existing file caches after upgrade

### Added
- PSR-16 SimpleCache adapter (`Cacheer\Psr\Psr16CacheAdapter`)
- PSR-3 logging compliance (`CacheLogger` extends `\Psr\Log\AbstractLogger`)
- `Cacheer::stats()` — returns driver class name, compression flag, and encryption flag
- `Cacheer::resetInstance()` — clears the shared static singleton (useful in tests)
- `Cacheer::setInstance()` — injects a custom singleton for testing
- `Cacheer::getOption($key, $default)` — reads a single option with fallback
- `CacheInvalidArgumentException` — PSR-16 compliant exception
- `DateInterval` and `null` TTL support in `putCache()`, `remember()`, `renewCache()`
- New examples: PSR-16 adapter, DateInterval TTL, falsy values, conditional add, stats/instance management

### Changed
- `CacheTimeConstants::CACHE_FOREVER_TTL` = `PHP_INT_MAX` (fixes 32-bit overflow)
- Redis: `PHP_INT_MAX` TTL now uses `SET` without expiry instead of `SETEX`
- Database: `PHP_INT_MAX` TTL stored as `'9999-12-31 23:59:59'`
- `remember()`, `increment()`, `getAndForget()` use `isSuccess()` instead of `!empty()` for falsy value support
- `FileCacheStore` now stores per-item TTL in the cache envelope

### Fixed
- Falsy values (`0`, `''`, `false`, `null`, `[]`) can now be cached and retrieved correctly
- `CACHE_FOREVER_TTL` no longer overflows on 32-bit systems
- `CacheLogger::rotateLog()` now writes rotated files to the correct directory
- Encryption IV is now random per write (was using a static IV derived from the key)

## [4.7.7] - 2025-12-XX

- Previous stable release. See [GitHub releases](https://github.com/silviooosilva/CacheerPHP/releases) for details.
