# Migrating from CacheerPHP v5 to v6

v6 is an instance-first rewrite. The engine is a small `Cacheer` kernel over a
minimal `Store` contract, with optional capabilities (batch, tags, locks, atomic
counters) declared by interface.

Migrating is mostly mechanical: rename the v5 methods to the v6 names (a Rector
set automates the common ones), move the positional namespace onto `scope()`, and
start v6 with a cold cache in its own keyspace (§5). There is no
drop-in v5 facade — the migration is the rename, not a runtime shim. If a service
can't move yet, keep it on `^5.2`, which still receives security and correctness
fixes (see §6).

## 1. Installation

```bash
composer require silviooosilva/cacheer-php:"^6.0@RC"
```

Until 6.0.0 is tagged stable, the `@RC` flag lets Composer pick the release
candidate; the same constraint moves to stable 6.0 once it ships. Without it,
Composer installs the stable v5 line.

v6 requires PHP 8.3+. The core installs with no backend clients: `ArrayStore`
and `FileStore` work out of the box. Redis (`predis/predis` or `ext-redis`) and a
PDO driver are only needed for those stores and stay in Composer `suggest`.

## 2. Construction: driver selection becomes a named constructor

v5 selected a driver fluently and migrated the database schema as a side effect.
v6 makes construction explicit and side-effect free.

| v5 | v6 |
|---|---|
| `(new Cacheer())->setDriver()->useFileDriver()` | `Cacheer::file('/var/cache')` |
| `->useDatabaseDriver()` | `Cacheer::database($pdo, 'cacheer_store')` |
| `->useRedisDriver()` | `Cacheer::redis($connection)` |
| array driver / tests | `Cacheer::inMemory()` |

The database schema is **never** created implicitly. Run the migration once,
explicitly (see §6).

## 3. Method mapping

Every v5 verb below is a method on the cache in v6 — you never reach past it to
the store, and the scope you are in is applied for you.

| v5 | v6 primary API | Notes |
|---|---|---|
| `putCache($k, $v, $ns, $ttl)` | `set($k, $v, $ttl)` | Namespace becomes `scope($ns)->set(...)` |
| `getCache($k, $ns, $ttl)` | `get($k)` | The read-time TTL is removed |
| `clearCache($k, $ns)` / `forget()` | `delete($k)` | Namespace becomes `scope($ns)->delete(...)` |
| `flushCache()` | `clear()` | Limited to the configured keyspace |
| `forever($k, $v)` | `forever($k, $v)` | Or `set($k, $v, null)` |
| `add($k, $v, $ns, $ttl)` | `add($k, $v, $ttl)` | Lock-serialized where the store can lock |
| `getAndForget()` / `pull()` | `pull($k, $default = null)` | Read and remove in one call |
| `has()` | `has()` | — |
| `missing()` | `missing()` | — |
| `getMany()` / `putMany()` | `many()` / `setMany()` | — |
| positional namespace | `scope('name')` or `in('name')` | Returns a cache of the same type |
| `tag($tag, ...$keys)` | `tag($key, ...$tags)` | Per key; tags are scope-namespaced |
| `flushTag($tag)` | `flushTag($tag)` | Returns how many were removed |
| `increment()` / `decrement()` | `increment()` / `decrement()` | Both kept |
| `renewCache($k, $ttl, $ns)` | `touch($k, $ttl)` | Extends TTL, keeps the value |
| `getAll($ns)` | `entries()` | Scope applied; yields entries with metadata |
| `lock($name, $ttl)` | `lock($name, $ttl)` | Lock names are scope-namespaced |
| `rememberForever()` | `rememberForever()` | — |
| `remember()` / `flexible()` | `remember()` / `flexible()` | Same intent, injected clock |
| `stats()` | `stats()` | Store, scope, policy, real capabilities |
| `useFormatter()` | `formatted()` | An immutable view; base reads stay raw |
| `appendCache()` | read → merge → `set()` | Explicit; wrap in `lock()` if concurrent |
| `isSuccess()` / `getMessage()` | `entry()->isHit()` or return value | Removed from core state |
| static `Cacheer::putCache(...)` | inject a `Cache` instance | No static facade in v6 |

The capability-backed rows (`increment`, `touch`, `tag`, `flushTag`, `lock`,
`entries`, `prune`) throw `UnsupportedCapabilityException` on a store that cannot
honor them. Every built-in store honors all of them; if you support pluggable
backends, ask `$cache->supports(AtomicStore::class)` first.

### Automated renames (Rector)

An optional Rector set ships at [`rector.php`](rector.php). It renames the
straightforward v5 methods on `Cacheer` (`putCache`→`set`, `getCache`→`get`, …).
It does **not** rewrite construction, move the namespace argument onto `scope()`,
or drop the read-time TTL — do those by hand using the tables above.

```bash
composer require rector/rector --dev
vendor/bin/rector process src --config rector.php --dry-run
```

## 4. Migrating incrementally

There is no runtime v5 shim, but you don't have to convert everything at once:

- Migrate one call site (or module) at a time to the `Cacheer` API; §3 and the
  Rector set cover most of the work.
- Keep the **same store** across old and new code during the transition — a
  `Cacheer::file(...)` reads whatever is already on disk (see §5), so migrated and
  unmigrated code share data.
- If a whole service can't move yet, pin it to `^5.2` and migrate it later. v5 and
  v6 are different major lines, not two APIs on one install.

## 5. Cached data: v6 starts cold

v6 does not read v5's cached data. The two versions use different storage
layouts and payload formats, so a v6 store simply never sees v5 entries: the
first v6 request for each key is a miss, and the value is recomputed and stored
in the v6 format. A cache is derived data, so nothing is lost — expect a warm-up
period with more misses than usual, as after any cache flush.

| Backend | v5 layout | v6 layout |
|---|---|---|
| File | `{dir}/[md5(namespace)/]md5(key).cache` | `{dir}/entries/…` and `{dir}/locks/…` |
| Database | table `cacheer_table` (default) | table `cacheer_store` (default), new schema |
| Redis | `{namespace}[ns:]key`, tags as `tag:…` | `{prefix}:e:…`, `{prefix}:t:…`, `{prefix}:l:…` |

Keep the two keyspaces apart:

- **File**: point v6 at a new directory. Sharing the v5 directory works — v6 only
  touches `entries/` and `locks/` — but a separate one makes cleanup trivial.
- **Database**: use a table name different from your v5 table. If both were
  `cacheer_store`, `DatabaseStoreSchema::migrate()` would keep the v5 table and
  v6 queries would then fail on its columns.
- **Redis**: use a v6 `$prefix` that is not your v5 namespace, or a separate
  logical database.

Once you are past your rollback window, delete the v5 data: the v5 cache
directory, the v5 table, or the v5 Redis keys (by your v5 namespace with
`SCAN`/`UNLINK`, or by letting their TTLs expire).

## 6. Database migration and rollback

Create the schema explicitly (idempotent), or preview it first with the CLI:

```php
use Silviooosilva\CacheerPhp\Stores\Support\DatabaseStoreSchema;

DatabaseStoreSchema::migrate($pdo, 'cacheer_store');
```

```bash
vendor/bin/cacheer migrate --dry-run   # print DDL without executing
vendor/bin/cacheer migrate             # create the schema
```

Rollback is a table drop. Because the cache is a derived store, dropping it only
discards cached data; it never loses source-of-truth data:

```php
DatabaseStoreSchema::drop($pdo, 'cacheer_store');
```

To roll back to v5 entirely: pin `silviooosilva/cacheer-php:^5.2` again and point
it at its original keyspace, which v6 never modified. v6 also never updated or
invalidated v5 entries, so v5 can serve values cached before the upgrade:
**flush the v5 cache when rolling back** unless that staleness is acceptable.
Then drop the v6 keyspace (`vendor/bin/cacheer clear --force`, or
`DatabaseStoreSchema::drop()`).

## 7. Support window

- **v6** is the actively developed line and receives features and fixes.
- **v5** receives **security and correctness fixes only** for 12 months after the
  v6.0 stable release. No new features are backported.
- Report vulnerabilities privately per [`SECURITY.md`](SECURITY.md).
