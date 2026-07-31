# CacheerPHP v6 — Examples

Runnable, self-contained examples for the v6 (instance-first) API. Each file
ends by printing `OK` and can be run repeatedly:

```bash
php Examples/v6/example01-simple-put-get.php
```

The `exampleNN-*.php` files mirror the 21 v5 examples one-for-one so you can see
exactly how each v5 idiom maps to v6. The short `01-quick-start.php`,
`02-scopes-and-observability.php`, and `04-builder-and-formatter.php` are extra
v6-native intros.

## v5 → v6 at a glance

| # | Example | v5 API | v6 API |
|---|---------|--------|--------|
| 01 | simple put/get | `new Cacheer(OptionBuilder…)`, `putCache`/`getCache` | `Cacheer::file()`, `set`/`get` |
| 02 | custom expiration | `OptionBuilder…expirationTime()->hour(2)` | per-write `set(…, ttl: '2 hours')` |
| 03 | clear & flush | `clearCache($k)`, `flushCache()` | `delete($k)`, `clear()` |
| 04 | namespace | `putCache($k,$v,$ns)` | `scope($ns)->set($k,$v)` |
| 05 | cache API response | `getCache`/`isSuccess`/`putCache` | `remember($k, ttl, $cb)` |
| 06 | existence check | `has($k,$ns)` + `isSuccess()` | `scope($ns)->has($k)` |
| 07 | append cache | `appendCache()` | read-modify-write: `get()` + `array_merge` + `set()` |
| 08 | renew TTL | `renewCache($k, 3600)` | `TouchStore::touch(Key, Ttl)` |
| 09 | PSR-16 adapter | `Psr16CacheAdapter` | `Psr16Cache` |
| 10 | flexible TTLs | int/string/DateInterval/null | same, on `set`/`remember`/`touch` |
| 11 | encryption | `useEncryption()` + `useCompression()` | `build()->gzip()->encryptWithPassphrases()` (AES-256-GCM) |
| 12 | stats & instance | `stats()`, `setInstance()`, `resetInstance()` | `MetricsCollector` + `InspectableStore` (no global singleton) |
| 13 | falsy values | `isSuccess()` hit detection | `entry()->isHit()` |
| 14 | add / conditional | `add()` | `has()`+`set()`, `LockingStore::lock()`, `compareAndSwap()` |
| 15 | monitor integration | autoload self-register | `Cacheer::instrumented($store, $bus)` + listener |
| 16 | aliases | `forget()`/`pull()`/`missing()` | `delete()` / `get()`+`delete()` / `!has()` |
| 17 | fluent namespace | `in()`/`namespace()`/`withoutNamespace()` | `scope()` (immutable, chainable) |
| 18 | counters w/ default | `increment($k,$a,$ns,$default,$ttl)` | `AtomicStore::increment(Key,$a,initial:,ttl:)` |
| 19 | putMany simple form | `putMany(['k'=>$v])` | `setMany(['k'=>$v])` |
| 20 | locks & atomic counters | `lock()`, `increment`/`decrement` | `LockingStore::lock()`, `AtomicStore::increment()` |
| 21 | stampede & SWR | `remember()`, `flexible()` | `remember()`, `flexible($k,$fresh,$stale,$cb)` |

## What changed and why

A few v5 conveniences have no 1:1 method in v6 — not because the capability is
gone, but because v6 keeps a tiny four-method store contract (`get`/`set`/
`delete`/`clear`) with advanced behavior exposed as explicit capability
interfaces (`AtomicStore`, `LockingStore`, `TouchStore`, `TaggableStore`, …).
Capability methods take value objects (`Key`, `Ttl`, `Scope`) and are called on
the **store**, which you build and inject into the `Cacheer` kernel:

```php
$store = new FileStore(__DIR__ . '/cache', clock: $clock);
$cache = new Cacheer($store, $clock);       // core get/set/remember/…
$store->increment(Key::named('hits'), 1, initial: 0);   // capability
```

Notably:

- **No `appendCache()`** — it was a merge helper; do the read-modify-write
  yourself (example 07), under a lock if concurrent.
- **No `add()`** — use a real lock for "first writer wins", `compareAndSwap()`
  for optimistic updates, or `has()`+`set()` for the simple single-process case
  (example 14).
- **No `decrement()`** — it is `increment()` with a negative amount (example 18/20).
- **No static singleton** — `setInstance()`/`resetInstance()`/the static facade
  are gone by design; v6 is instance-first. The useful half of `stats()` is now
  first-class observability (example 12).
- **PSR-16 keys** reserve `{}()/\@:` per the spec, so use `.` (not `:`) as a key
  separator with the PSR-16 adapter (example 09).
