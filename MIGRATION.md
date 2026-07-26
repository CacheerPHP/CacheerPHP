# Migrating from CacheerPHP v5 to v6

v6 is an instance-first rewrite. The engine is a small `Cache` kernel over a
minimal `Store` contract, with optional capabilities (batch, tags, locks, atomic
counters) declared by interface. You can migrate in one of two ways:

1. **Bridge first, then modernize.** Swap your v5 object for the
   [`LegacyCacheer`](src/Compat/LegacyCacheer.php) bridge — it keeps the v5
   method names — then move call sites to the `Cache` API at your own pace.
2. **Rewrite directly.** Replace call sites using the mapping below.

Either way, follow one path from installation to a passing test suite.

## 1. Installation

```bash
composer require silviooosilva/cacheer-php:^6.0
```

v6 requires PHP 8.3+. The core installs with no backend clients: `ArrayStore`
and `FileStore` work out of the box. Redis (`predis/predis` or `ext-redis`) and a
PDO driver are only needed for those stores and stay in Composer `suggest`.

## 2. Construction: driver selection becomes a named constructor

v5 selected a driver fluently and migrated the database schema as a side effect.
v6 makes construction explicit and side-effect free.

| v5 | v6 |
|---|---|
| `(new Cacheer())->setDriver()->useFileDriver()` | `Cache::file('/var/cache')` |
| `->useDatabaseDriver()` | `Cache::database($pdo, 'cacheer_store')` |
| `->useRedisDriver()` | `Cache::redis($connection)` |
| array driver / tests | `Cache::inMemory()` |

The database schema is **never** created implicitly. Run the migration once,
explicitly (see §6).

## 3. Method mapping

| v5 | v6 primary API | Notes |
|---|---|---|
| `putCache($k, $v, $ns, $ttl)` | `set($k, $v, $ttl)` | Namespace becomes `scope($ns)->set(...)` |
| `forever($k, $v)` | `set($k, $v, null)` | `null` TTL means forever |
| `getCache($k, $ns, $ttl)` | `get($k)` | The read-time TTL is removed |
| `clearCache($k, $ns)` | `delete($k)` | Namespace becomes `scope($ns)->delete(...)` |
| `flushCache()` | `clear()` | Limited to the configured keyspace |
| `getAndForget()` / `pull()` | bridge `pull()` | Atomicity reported by capability |
| `has()` / `missing()` | `has()` | — |
| positional namespace | `scope('name')` | Returns a scoped cache |
| `tag($tag, ...$keys)` | `TaggableStore::tag()` | Capability, not core |
| `increment()` / `decrement()` | `AtomicStore::increment()` | Capability, not core |
| `isSuccess()` | `entry()->isHit()` or return value | Removed from core state |
| `remember()` / `flexible()` | `remember()` / `flexible()` | Same intent, injected clock |

### Automated renames (Rector)

An optional Rector set ships at [`rector.php`](rector.php). It renames the
straightforward v5 methods on `Cacheer`/`LegacyCacheer`. It does **not** rewrite
construction, move the namespace argument onto `scope()`, or drop the read-time
TTL — do those by hand using the tables above.

```bash
composer require rector/rector --dev
vendor/bin/rector process src --config rector.php --dry-run
```

## 4. The compatibility bridge

[`LegacyCacheer`](src/Compat/LegacyCacheer.php) is a drop-in for the v5 surface:

```php
use Silviooosilva\CacheerPhp\Compat\LegacyCacheer;

$cache = LegacyCacheer::file('/var/cache');       // or ::inMemory()
$cache->putCache('user:1', $user, 'accounts', 3600);
$user = $cache->getCache('user:1', 'accounts');
```

Enable deprecations in development to locate call sites to migrate. They are
**silent by default** so production logs stay clean:

```php
$cache = LegacyCacheer::file('/var/cache', emitDeprecations: true);
```

## 5. Data compatibility and rewrite-on-read

v6 writes an authenticated, versioned envelope. It can still **read** values
written by v5 during the migration window: construct the store's pipeline with a
[`V5PayloadReader`](src/Storage/Compat/V5PayloadReader.php) that mirrors the
compression/encryption v5 used (v5 payloads are not self-describing).

`FileStore` and `DatabaseStore` can also **rewrite on read** — the first time a
legacy value is read it is re-encoded in the v6 envelope in place, preserving its
creation and expiry timestamps:

```php
$pipeline = PipelineConfig::default()->withV5Reader(new V5PayloadReader(compression: true));
$store = new FileStore('/var/cache', $pipeline->codec(), migrateLegacyOnRead: true);
```

Notes and limitations:

- v5's AES-256-**CBC** payloads are unauthenticated. A wrong key or tampering can
  only surface as a failed `unserialize`, never cryptographically. New writes
  always use the authenticated v6 envelope.
- Rewrite-on-read is opt-in (`migrateLegacyOnRead: true`) so a read-only rollout
  never mutates data unexpectedly.
- Redis entries migrate naturally: they are rewritten in the v6 envelope on their
  next write, and legacy reads keep working until then.

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

To roll back to v5 entirely: keep the v5 keyspace untouched (v6 rewrite-on-read
is opt-in), pin `silviooosilva/cacheer-php:^5.2` again, and clear any v6-only
envelopes v6 may have written (`vendor/bin/cacheer clear --force`).

## 7. Support window

- **v6** is the actively developed line and receives features and fixes.
- **v5** receives **security and correctness fixes only** for 12 months after the
  v6.0 stable release. No new features are backported.
- Report vulnerabilities privately per [`SECURITY.md`](SECURITY.md).
