# Changelog

All notable changes to CacheerPHP will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [6.0.0] — Instance-first rewrite (release candidate)

CacheerPHP 6.0 is a ground-up, instance-first rewrite. A small `Cacheer` kernel
runs over a minimal four-method `Store` contract; everything else is an optional
capability. Caches are never global — you construct one and inject it — and the
package itself runs nothing at autoload time; the one process-global is the
opt-in `Observability\Telemetry` tap, dormant until a listener is registered. v5
remains available on its own `5.x` line during migration.

### Architecture changes since the first 6.0 preview

- **One cache type instead of four.** `ScopedCacheer` and `PolicyCacheer` are
  gone; `scope()`, `in()`, and `withPolicy()` all return a `Cacheer`. The
  scope × policy × formatting matrix now closes — previously a scoped cache could
  not take a policy, a policy-bound cache could not be scoped, and the formatted
  view silently lacked `flexible()`, `many()`, `setMany()`, and `deleteMany()`.
- **New `Cache` interface.** Type-hint it in application code; `Psr16Cache` and
  `Psr6Pool` now accept it, so a scoped or policy-bound cache can back a PSR
  adapter. Backends still implement `Store`, not this.
- **Capabilities moved onto the cache.** `increment()`, `decrement()`, `touch()`,
  `tag()`, `flushTag()`, `lock()`, `entries()`, and `prune()` are methods on the
  cache, with the scope applied. Reaching past the cache to the store and
  building a `Key` by hand — which the previous examples had to do — silently
  ignored the scope and targeted a different entry. Tags and lock names are
  namespaced by scope too.
- **Fixed: decorators claimed capabilities they could not honor.** PHP has no
  conditional interface implementation, so `TieredStore`, `ResilientStore`, and
  `InstrumentedStore` declare every capability interface. `instanceof` was
  therefore true for a wrapper around a store that could not honor it, and
  `remember()` on a custom store **threw `UnsupportedCapabilityException` as soon
  as the store was wrapped** — including implicitly, when `cacheerphp/monitor`
  auto-instrumented it. Capability questions now go through the new
  `Capabilities` helper and the `CapabilityAware` contract, which answer for the
  store that will actually run the operation; `remember()` and `flexible()`
  degrade to a plain compute instead of failing. Ask with `$cache->supports()`.
- **v5 verbs restored.** `forever()`, `rememberForever()`, `missing()`, `add()`,
  `pull()` (v5's `getAndForget`), `decrement()`, `touch()` (v5's `renewCache`),
  `stats()`, and `in()` as an alias of `scope()`. `add()` is lock-serialized
  where the store can lock, rather than the racy `has()` + `set()` the previous
  examples recommended.
- **Removed.** `Cacheer::array()` (a verbatim alias of `inMemory()`) and the
  unused v5 exception island (`BaseException`, `CacheFileException`,
  `CacheDatabaseException`, `CacheRedisException`, `ConnectionException`).
- **v5 compatibility reader removed (since RC1).** `V5PayloadReader`,
  `PipelineConfig::withV5Reader()`, `EnvelopeCodec::isLegacyBlob()`, and the
  `migrateLegacyOnRead` option on `FileStore`/`DatabaseStore` are gone. They only
  ran on records already in v6 layout, so real v5 files, tables, and Redis keys
  were never read; and with a reader configured, any non-envelope blob bypassed
  the encrypted read path and reached an unrestricted `unserialize()`. Upgrades
  now start with a cold cache in a separate keyspace (MIGRATION.md §5), and every
  non-envelope blob is rejected.

### Highlights

- **Kernel.** One explicit, immutable `Cacheer` behind a `Cache` interface, over
  typed `Key`, `Scope`, `Ttl`, and `CacheEntry` value objects; time is an
  injected `Clock`. Scope and policy are state on the object, so every
  combination composes — `in('billing')->withPolicy($p)->increment('hits')`.
- **Stores & capabilities.** `ArrayStore`, `FileStore`, `DatabaseStore` (SQLite,
  MySQL/MariaDB, PostgreSQL), and `RedisStore`, each declaring only the
  capabilities it can guarantee (batch, touch, prune, inspect, scoped flush,
  tags, atomic, locks). All pass one shared conformance suite.
- **Flagship features.** `TieredStore` (L1/L2 with generation coherence),
  `ResilientStore` (circuit-breaker fallback), single-flight `remember()`,
  stale-while-revalidate `flexible()`, and typed `CachePolicy` — all composable.
- **Storage pipeline.** serialize → optional gzip → optional authenticated
  AES-256-GCM into a versioned, tamper-evident envelope, with key rotation.
- **Standards & observability.** PSR-16 and PSR-6 adapters, a PSR-3 logging
  subscriber, a PSR-14 event bridge, typed cache events, and a `MetricsCollector`
  (values are never captured).
- **Operations.** A `cacheer` CLI (`doctor`, `stats`, `inspect`, `prune`,
  `clear`, `migrate`) with `--dry-run` and `--json`.
- **Ergonomics.** A fluent `Cacheer::build()` that assembles store + pipeline +
  policy in one chain (the v6 take on v5's OptionBuilder), and a
  `CacheDataFormatter` (`toJson`/`toArray`/`toObject`/`toString`) reachable
  standalone or through an opt-in `formatted()` view.
- **Migration.** An optional Rector rename set, a cold-keyspace upgrade path, and
  end-to-end fresh-install / v5-upgrade rehearsals in CI.

### Fixed since RC1

- Scoped `remember()`, `rememberForever()`, `add()`, `pull()`, `flexible()`,
  and serve-stale-on-error reads now apply the scope once. Previously they read
  a doubly-scoped key, so scoped `remember()` recomputed on every call, `add()`
  overwrote existing entries, and `pull()` always returned the default.
- `DatabaseStore` matches scopes literally when clearing or inspecting a scope.
  A `_`, `%`, or (on MySQL/PostgreSQL) `\` in a scope name was treated as a
  LIKE pattern character, so clearing `tenant_a` also deleted `tenantXa/…`.
- `DatabaseStore` compares scopes and tags exactly. SQLite's case-insensitive
  LIKE let `clearScope()` on `Tenant` delete `tenant/…`, and MySQL's default
  collation also merged case, accents, and Unicode normalization forms for
  `clearScope()`, `entries()`, and `flushTag()` (`Users` flushed `users`).
- `DatabaseStore` rejects a scope or tag longer than its 255-character column
  before writing, on every driver. Previously MySQL strict mode and PostgreSQL
  failed with a driver error, and MySQL non-strict mode silently truncated the
  scope, so the entry escaped `entries()` and scoped `clear()`.
- `DatabaseStore` lock names are stored hashed. On MySQL, `lock('Report')` and
  `lock('report')` no longer contend, and names over 255 characters can be
  acquired instead of always failing.
- `RedisStore` escapes its prefix in SCAN patterns, so `clear()`, `prune()`,
  `entries()`, and `clearScope()` on a prefix such as `app*` no longer reach
  `appX`. A prefix containing a reserved `:e`, `:t`, `:l`, `:lk`, or `:kt` segment —
  which would nest it inside another store's keyspace — is now rejected with an
  `InvalidArgumentException`.
- An encrypting pipeline refuses envelopes that declare no encryption, throwing
  `UnsupportedEnvelopeException`. Anyone able to write to the backend could
  previously plant an unauthenticated plaintext envelope that was unserialized.
  Clear the cache (or use a new keyspace) when enabling encryption.
- The value limit now applies on read to plain and encrypted-only pipelines too,
  not just to decompression, so no oversized payload reaches `unserialize()`. A
  negative limit is rejected with `InvalidArgumentException`.
- An envelope cut off right after its magic bytes is reported as
  `CorruptedPayloadException` instead of raising a PHP warning and a misleading
  "version 0" error.
- `DatabaseStore::touch()` no longer revives an expired entry; like every other
  store it now reports a miss and leaves the entry expired.
- Negative caching only ever shortens a lifetime. An explicit forever
  (`forever()`, `rememberForever()`, `'forever'`) of an empty value stays forever,
  and an explicit TTL shorter than the negative TTL is no longer lengthened.
- `TieredStore` keeps a promoted value's original creation time and absolute
  expiry in L1. A promoted stale value used to look newly written, restarting its
  `flexible()` freshness, and a capped L1 copy reported the cap as its expiry.
- `increment()` past `PHP_INT_MAX`/`PHP_INT_MIN` throws
  `StoreOperationFailedException` on every store and keeps the previous value;
  PHP used to turn the counter into a float, breaking every later increment.
- A serve-stale grace that would overflow the TTL raises `InvalidTtlException`
  instead of a `TypeError`.
- Ending an entry ends its tag membership on every store. After `delete()`,
  `deleteMany()`, `clearScope()`, or `clearTag()` of one of its tags, the old
  tag could still delete a new, untagged entry written under the same key
  (ArrayStore also after `clear()`). Tagging a key with no live entry now records
  nothing. File and Redis keep a small key-to-tags index for this, which on
  Redis uses the new reserved `:kt` prefix segment. Membership still survives
  overwrites and expiry, so a refreshed value stays tagged.
- Monitoring no longer changes cache behavior. `InstrumentedStore` forwards
  `getMany`/`setMany`/`deleteMany` to the inner store's native batch, so a
  monitored database batch rolls back as a whole instead of committing entries
  one by one. A throwing listener or custom dispatcher can no longer fail a
  completed operation or replace a backend error, in the instrumented store,
  the kernel, or `TieredStore`. `MetricsCollector` no longer counts read sizes
  as `bytes_written`.
- `flexible()` never serves a value older than the caller's stale window; a value
  written under the same key with a longer TTL used to be served stale forever.
  A burst of stale reads now queues one refresh per key instead of one per read
  (the refresh lock marks it pending), a queued refresh re-checks freshness
  first, and a refresh that fails or cannot be scheduled releases its pending
  state.
- PSR adapters follow their contracts. A PSR-6 item saved with
  `saveDeferred()` reads as a hit before `commit()` (it read as `null`), is
  queued as a copy, and its relative expiry starts when it is queued. Native key
  rules (length, control characters) and non-string batch keys now raise the
  PSR `InvalidArgumentException` instead of `InvalidKeyException` or a silent
  cast, and so does an out-of-range TTL. Write methods return `false` on a store
  failure instead of throwing.
- `TieredStore` bounds how stale a worker's L1 can get: without an explicit
  `l1MaxTtl`, L1 copies now live at most 60 seconds, so a single-key change by
  another worker is seen within that bound instead of never (until the value
  expired). Pass `Ttl::forever()` to keep the previous unbounded behavior.
- `ResilientStore` settles its guarantees. Only backend outages trip the breaker
  and fail over; programming errors and bad data (corrupt payloads, invalid
  keys, overflows) are rethrown instead of being hidden by the fallback. The
  primary's write result is authoritative (writes used to return the fallback's
  result). Counters, compare-and-swap, and locks run on the primary alone and
  fail closed during an outage, instead of diverging on the fallback or handing
  two workers "the same" lock. Writes made on the fallback alone during an
  outage are invalidated on the primary when it recovers, so deleted or changed
  values no longer reappear.
- File and Redis `increment()`/`compareAndSwap()` no longer run unprotected when
  their per-key lock times out; they throw `StoreOperationFailedException` and
  leave the entry unchanged. `add()` likewise throws on a lock timeout instead of
  falling back to an unlocked check-and-write that could report a false win.
- `remember()` re-reads the entry after timing out on another worker's lock, so a
  value that worker stored is used instead of being computed again.
- A repeated `acquire()` on a held Redis lock no longer drops ownership, which
  left `release()` a no-op and the lock stuck until its TTL.

### Breaking changes

- Instance-first: the static/global facade is gone; construct and inject a
  `Cacheer`. There is no drop-in v5 shim — migrate with the Rector set + mapping
  table (MIGRATION.md), or stay on `^5.2`.
- `get()` no longer accepts a read-time TTL; positional namespaces become
  `scope()`; success is a return value or `entry()->isHit()`, not mutable state.
- Minimum PHP is now **8.3**. Driver clients and extensions are optional
  (`suggest`); the core installs for Array/File users with none of them.
- See [MIGRATION.md](MIGRATION.md) for the full mapping and rollback steps, and
  [KNOWN_LIMITATIONS.md](KNOWN_LIMITATIONS.md) for documented edges.

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
