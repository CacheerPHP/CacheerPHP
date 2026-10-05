# CacheerPHP

<p align="center">
  <a href="https://github.com/CacheerPHP/CacheerPHP">
    <picture>
      <source media="(prefers-color-scheme: dark)" srcset="./art/logo.svg">
      <source media="(prefers-color-scheme: light)" srcset="./art/logo-light.svg">
      <img src="./art/logo-light.svg" width="420" alt="CacheerPHP"/>
    </picture>
  </a>
</p>

<p align="center">
  <strong>An explicit, instance-first PHP cache with a tiny core, optional capabilities, and zero framework lock-in.</strong>
</p>

<p align="center">
  <a href="https://github.com/CacheerPHP/CacheerPHP/releases"><img src="https://img.shields.io/github/release/CacheerPHP/CacheerPHP.svg?style=for-the-badge&color=315dc9" alt="Latest stable release"/></a>
  <img src="https://img.shields.io/packagist/dependency-v/silviooosilva/cacheer-php/PHP?style=for-the-badge&color=315dc9" alt="PHP Version"/>
  <img src="https://img.shields.io/packagist/dt/silviooosilva/cacheer-php?style=for-the-badge&color=315dc9" alt="Downloads"/>
  <a href="https://github.com/silviooosilva"><img src="https://img.shields.io/badge/maintainer-@silviooosilva-315dc9.svg?style=for-the-badge&color=315dc9" alt="Maintainer"/></a>
</p>

---

> **CacheerPHP 6.x** provides instance-first caching over a four-method `Store`
> contract. Optional interfaces add batching, tags, locks, and counters; decorators
> add tiering, resilience, and instrumentation. Configure serialization,
> compression, and encryption through the storage pipeline. Construct the cache
> you need and inject it; the core package runs no code at autoload time.
> Upgrading from v5? See [Migrating from v5](#migrating-from-v5).

## Five-minute quick start

```sh
composer require silviooosilva/cacheer-php:"^6.0@RC"
```

The 6.x line is a release candidate; `v6.0.0-RC1` is available on
[Packagist](https://packagist.org/packages/silviooosilva/cacheer-php).
The `@RC` flag allows release candidates, and the same constraint accepts stable
6.0 once published. An unversioned install currently selects the stable 5.x line.

The core installs with **no backend clients and no required extensions** —
`ArrayStore` and `FileStore` work out of the box.

```php
<?php
require_once __DIR__ . '/vendor/autoload.php';

use Silviooosilva\CacheerPhp\Cacheer;

// Dependency-free, in-process. Great for tests and short CLI runs.
$cache = Cacheer::inMemory();

// Write with a TTL (seconds, "10 minutes", a DateInterval, or null = forever).
$cache->set('user:42', ['name' => 'Ada'], ttl: '10 minutes');

// Read (returns your default on a miss).
$user = $cache->get('user:42', default: null);

// On a miss, run the callback, store the result, and return it.
$report = $cache->remember('report:daily', ttl: 3600, callback: function () {
    return expensive_report();
});

// Existence and deletion.
$cache->has('user:42');     // true
$cache->delete('user:42');
```

Swap the store, keep the API:

```php
$cache = Cacheer::file('/var/cache/app');      // persistent, dependency-free
$cache = Cacheer::database($pdo, 'cacheer');   // inject your own PDO
$cache = Cacheer::redis($connection);          // predis or phpredis adapter
```

PDO stores need a driver and an explicitly created schema:

```php
use Silviooosilva\CacheerPhp\Stores\Support\DatabaseStoreSchema;

$pdo = new PDO('sqlite:' . __DIR__ . '/cache.sqlite');
DatabaseStoreSchema::migrate($pdo, 'cacheer');
$cache = Cacheer::database($pdo, 'cacheer');
```

Schema creation is an explicit setup step. Constructing the store does not run it.

## Why v6

- **Tiny core, honest capabilities.** A store implements four methods; extra
  behavior is declared by interface, and `$cache->supports(...)` answers
  truthfully even through decorators — a backend never pretends to guarantee
  something it can't. See [CAPABILITIES.md](CAPABILITIES.md).
- **One cache type.** Scope, policy, and capabilities are state on one object, so
  every combination composes — a scoped cache still has a policy, a policy-bound
  cache still scopes, and both still increment, tag, and lock. Type-hint the
  `Cache` interface and any of them is substitutable.
- **Instance-first.** Construct several caches side by side and inject them where
  needed. The core does not load `.env`, create database schemas, or change the
  global timezone at autoload time. [`Telemetry`](#observability-and-the-one-global)
  provides an optional process-global observability tap.
- **Scopes instead of stringly namespaces.** `->scope('billing')` (or `->in()`)
  is an isolated keyspace you can clear on its own — and it applies to counters,
  tags, and locks too, not just to get/set.
- **Composable decorators.** Tiered (L1/L2), resilient (circuit-breaker
  fallback), and instrumented (typed events + metrics) all wrap any store.
- **Stampede protection.** `remember()` coordinates misses when locking is
  available; `flexible()` serves a bounded stale value through the chosen executor.
- **Configurable storage.** Values are serialized → optionally compressed →
  optionally AES-256-GCM encrypted into a versioned envelope. Encryption
  authenticates the payload; an unencrypted envelope is not authenticated.
- **Standards-first.** PSR-16 and PSR-6 adapters, a PSR-3 logging subscriber, and
  a PSR-14 event bridge ship in the box.
- **Deterministic tests.** Time is an injected `Clock`; the suite uses a
  `FakeClock` and needs no `sleep()`.

## Core recipes

### Scopes

```php
$cache->scope('reports')->set('daily', $rows);
$cache->in('billing')->set('daily', $invoice);      // in() is an alias of scope()
$cache->scope('reports')->clear();                  // clears only that scope

// Scope applies to every operation, capabilities included.
$billing = $cache->in('billing');
$billing->increment('invoices:issued');             // never touches another scope
$billing->lock('nightly-import', 60);               // per-scope mutex
```

### One type, every combination

```php
use Silviooosilva\CacheerPhp\Contracts\Cache;

function report(Cache $cache): string { /* ... */ }   // type-hint the interface

$tuned = $cache->in('reports')->withPolicy($policy);  // order does not matter
$tuned->formatted()->get('daily')->toJson();          // views compose too
```

### Policies

```php
use Silviooosilva\CacheerPhp\Config\CachePolicy;

$policy = CachePolicy::defaults()
    ->withTtl('10 minutes')
    ->withJitter(0.10)
    ->withNegativeTtl('30 seconds')
    ->withServeStaleOnError('2 minutes');

$cache = $cache->in('reports')->withPolicy($policy);
```

The default applies when a write omits its TTL. Negative caching only shortens
empty results; it preserves a shorter explicit TTL and explicit forever storage.
Use `forever()`, `rememberForever()`, or `Ttl::forever()` for explicit no-expiry
storage under a policy. `flexible()` keeps its requested stale window unchanged
by jitter or negative caching. See the
[policies guide](https://cacheerphp.com/docs/v6/en/guides/policies/).

### Stampede protection & stale-while-revalidate

```php
// Coordinate a miss through the store's lock when available.
$value = $cache->remember('key', 3600, fn () => build());

// Fresh for 30s, eligible stale data until 300s; older data recomputes synchronously.
$value = $cache->flexible('feed', fresh: 30, stale: 300, callback: fn () => build());
```

`remember()` waits up to five seconds for its internal lock. After a timeout it
rechecks the entry, then computes if still missing. Stores without locking use a
plain compute-and-store; Array coordination is local to one process. Coordination
lasts only while the lock's lease holds, so callbacks are not guaranteed exactly once.

`flexible()` measures age from the original creation time, including promoted
values, and rechecks freshness before a queued refresh. A refresh lock prevents
duplicate pending work while its lease holds. On a store without locking, stale
reads can queue multiple tasks, which each recheck freshness before computing.

The default executor runs refreshes inline. For deferred work:

```php
use Silviooosilva\CacheerPhp\Support\AfterResponseDeferredExecutor;

$executor = new AfterResponseDeferredExecutor();
$cache = new Cacheer($store, executor: $executor);
```

The executor flushes at shutdown and sends the response first where
`fastcgi_finish_request()` is available. In a long-running worker, call
`$executor->flush()` after each request or job. See the
[refresh guide](https://cacheerphp.com/docs/v6/en/guides/stale-while-revalidate/).

### Tiering, resilience, observability

```php
use Silviooosilva\CacheerPhp\Observability\{EventBus, MetricsCollector};
use Silviooosilva\CacheerPhp\Kernel\Ttl;

$cache = Cacheer::tiered($l1, $l2, l1MaxTtl: Ttl::seconds(10));
$cache = Cacheer::resilient($primary, $fallback);   // fall back when the breaker trips

$events = new EventBus();
$metrics = new MetricsCollector();
$events->listen($metrics->record(...));
$cache = Cacheer::instrumented($store, $events);    // value capture is off by default
$metrics->snapshot();                             // hit_rate, latency, bytes, ...
```

Tiered caches write L2 first and retain the source value's age and expiry on
promotion. The default L1 cap is **60 seconds**; another worker's single-key
change may remain stale locally until that cap expires. Bulk invalidation uses a
shared generation token. See the
[tiered guide](https://cacheerphp.com/docs/v6/en/guides/tiered-caching/).

Resilient caches fail over on backend outages. Programming errors and corrupt
payloads propagate; successful primary writes keep the primary's result even if
mirroring fails. Counters, CAS, and locks stay on the primary and do not fail over.
Before serving the recovered primary, each process reconciles its own fallback-only
mutations. See the
[resilient guide](https://cacheerphp.com/docs/v6/en/guides/resilient-store/).

Instrumentation preserves native batches and their transaction behavior. Listener
failures do not change completed cache operations, and reads do not increase
`bytes_written`.

### Encryption & compression

```php
use Silviooosilva\CacheerPhp\Config\PipelineConfig;
use Silviooosilva\CacheerPhp\Storage\Encryption\Keyring;

// Load a persisted raw 32-byte key from your application's secret configuration.
$keyring = new Keyring(['current' => $encryptionKey], activeId: 'current');

$pipeline = PipelineConfig::default()
    ->withGzip()
    ->withKeyring($keyring)
    ->withMaxValueBytes(2_000_000);

$cache = Cacheer::file('/var/cache/app', $pipeline);
```

Reuse the same key across workers and restarts. Keep retired keys in the keyring
while old entries still need to be read. An encrypting pipeline rejects plaintext
envelopes, so enabling encryption on an existing cache needs a fresh keyspace or
a clear. The value-size limit covers plain, compressed, and encrypted reads before
deserialization; `0` means no configured limit.

Native PHP serialization supports objects and allows classes by default. Restrict
restoration on a shared backend when needed:

```php
use Silviooosilva\CacheerPhp\Storage\Serializer\PhpSerializer;

$pipeline = $pipeline->withSerializer(new PhpSerializer(allowedClasses: false));
```

See [SECURITY.md](SECURITY.md) and the
[storage guide](https://cacheerphp.com/docs/v6/en/api/compression-encryption/).

### Fluent configuration — `Cacheer::build()`

Assemble a store, storage pipeline, and default policy in one chain (the v6 take on
v5's OptionBuilder — it returns a ready cache, not an options array):

```php
$cache = Cacheer::build()
    ->file('/var/cache/app')
    ->gzip()
    ->encrypt($keyring)
    ->maxValueBytes(2_000_000)
    ->defaultTtl('10 minutes')
    ->jitter(0.10)
    ->create();
```

### Formatting reads

The default PHP serializer preserves serializable values. To reshape one on the
way out, use the `CacheDataFormatter` — standalone, or via a fluent `formatted()` view:

```php
use Silviooosilva\CacheerPhp\Support\CacheDataFormatter;

$json = (new CacheDataFormatter($cache->get('user:1')))->toJson();  // wrap any value
$json = $cache->formatted()->get('user:1')->toJson();               // fluent view
```

### Counters, tags, locks, TTL renewal (capabilities)

Capabilities are implemented by the store and reached on the cache, so the scope
is applied for you and one clear exception names anything the backend can't do:

```php
use Silviooosilva\CacheerPhp\Contracts\AtomicStore;

$cache->increment('visits');                  // AtomicStore
$cache->decrement('stock', 5, initial: 100);
$cache->touch('session:1', '1 hour');         // TouchStore — renew, keep value
$cache->set('product:1', ['id' => 1], ttl: 600);
$cache->tag('product:1', 'products');         // tag an already-stored key
$cache->flushTag('products');
$lock = $cache->lock('import', 30);           // LockingStore
$cache->entries();                            // InspectableStore — this scope
$cache->prune();                              // PrunableStore

// Pluggable backend? Ask first — this is honest through decorators too.
if ($cache->supports(AtomicStore::class)) { /* ... */ }
```

`touch()` preserves value age and cannot revive expired data. Tags survive
overwrites and end when the entry is deleted or cleared. Redis prefixes must not
include the reserved `:e`, `:t`, `:l`, or `:kt` segments.

Check the result of a lock's `block()` before entering a critical section and
release it in `finally`. File locks last until release or process exit; their TTL
does not expire a live `flock` handle. Array locks coordinate one process; shared
Redis/PDO locks coordinate through their backend.

### Everyday verbs

```php
$cache->forever('config', $config);                    // no expiry, stated plainly
$cache->add('job:queued', 1, ttl: 60);                 // store only if absent
$cache->pull('flash');                                 // read once, then remove
$cache->missing('user:1');                             // inverse of has()
$cache->rememberForever('version', fn () => compute());
$cache->stats();                                       // store, scope, capabilities
```

On a lock-capable store, `add()` coordinates competing `add()` calls and throws
on lock timeout. Without locking it is best-effort; ordinary `set()` calls do not
share that coordination. `pull()` reads then deletes and is not an atomic consume.

## Observability and the one global

Everything above is instance-scoped. `Observability\Telemetry` is the single
deliberate exception, and it is worth being precise about what it is:

- It holds **process-global state** — a static listener list.
- It is **dormant**: with no listeners registered, caches use the uninstrumented
  path. Register listeners before constructing caches you want to observe.
- **This package registers nothing.** `silviooosilva/cacheer-php` declares only
  PSR-4 autoloading, so installing it executes no code.

It exists so a telemetry package can observe caches it did not construct:

```php
use Silviooosilva\CacheerPhp\Observability\Telemetry;

Telemetry::listen(fn ($event) => $myCollector->record($event));  // opt in
// Construct the caches to observe after registering the listener.
Telemetry::reset(); // clear the global registrations for future caches
```

Existing instrumented caches retain their dispatcher. Resetting the tap does not
remove instrumentation from a cache already constructed. Value capture is off by
default; enable it only deliberately with an appropriate redaction policy.

Installing [`cacheerphp/monitor`](https://github.com/CacheerPHP/monitor)
**does** add an autoload-time side effect: that package declares
`autoload.files`, and its bootstrap registers a listener as soon as
`vendor/autoload.php` is loaded. That is the point of it — zero-wiring
monitoring — but it is a real side effect, from that package, and you opt into it
by installing it.

If you want no process-global state at all, never call `Telemetry::listen()` and
don't install the monitor: wire observability explicitly with
`Cacheer::instrumented($store, $events)` instead.

## PSR adapters

```php
use Silviooosilva\CacheerPhp\Psr\{Psr16Cache, Psr6Pool};
use Silviooosilva\CacheerPhp\Support\SystemClock;

$psr16 = new Psr16Cache($cache);                   // Psr\SimpleCache\CacheInterface
$pool  = new Psr6Pool($cache, new SystemClock());  // Psr\Cache\CacheItemPoolInterface

$item = $pool->getItem('user42')->set(['name' => 'Ada'])->expiresAfter(600);
$pool->saveDeferred($item);
$pool->getItem('user42')->get();                  // visible before commit
$pool->commit();                                 // persist queued saves
```

PSR keys reject reserved characters (including `:`), empty keys, control
characters, and keys beyond 1,024 bytes. Invalid inputs raise the PSR invalid-argument
exception. Write failures return `false`; reads propagate backend errors. Deferred
PSR-6 lifetimes begin when queued, so delaying `commit()` does not extend expiry.
See the [adapter reference](https://cacheerphp.com/docs/v6/en/api/psr16-adapter/).

## Operations CLI

```sh
vendor/bin/cacheer doctor        # PHP, optional extensions, and configuration
vendor/bin/cacheer stats         # store + capabilities + entry count
vendor/bin/cacheer inspect user:42 # hit/expiry details; value is not printed
vendor/bin/cacheer prune --dry-run
vendor/bin/cacheer clear --force
vendor/bin/cacheer migrate --dry-run   # print schema DDL without executing
```

Point the CLI at a `cacheer.config.php` that returns a `Store` (or
`['store' => ..., 'pdo' => ..., 'table' => ...]`).

## Migrating from v5

v6 is a new major with an instance-first API. Migrating is mostly mechanical:

- Rename the v5 methods to the v6 names — `putCache`→`set`, `getCache`→`get`,
  `flushCache`→`clear`, and the positional namespace → `scope()`. The optional
  [`rector.php`](rector.php) set automates the common renames.
- Cached data starts cold: v6 uses its own storage layout and rejects v5 payloads.
  Use a separate cache directory, Redis prefix, or SQL table, and recompute values
  on first use. Keep the v5 keyspace through the rollback window.
- Keep services that have not migrated on `^5.2`. The v5 line receives security
  and correctness fixes for 12 months after the 6.0 stable release.

The method-by-method mapping and database migration/rollback steps are in
**[MIGRATION.md](MIGRATION.md)**.

## Building a custom store

Implement four methods, add capability interfaces you can honor, and prove it
with the shared conformance suite — no need to read the built-in store source.
See **[WRITING_A_STORE.md](WRITING_A_STORE.md)**.

The conformance suite lives under `tests/Support/`. Composer does not load a
dependency's `autoload-dev`, so a consumer must map the helpers in its own project:

```json
{
    "require-dev": {
        "phpunit/phpunit": "^12.0"
    },
    "autoload-dev": {
        "psr-4": {
            "Tests\\Support\\": "vendor/silviooosilva/cacheer-php/tests/Support/"
        }
    }
}
```

Merge this into your project's `composer.json`, install the development dependencies,
and run `composer dump-autoload`. Extend `Tests\Support\StoreConformance`, return
your store from `createStore(FakeClock $clock)`, and run it with `vendor/bin/phpunit`.
Inject the supplied clock so expiry checks stay deterministic. See the
[custom-store guide](https://cacheerphp.com/docs/v6/en/guides/custom-stores/).

## Requirements

- **PHP 8.3+**
- `ext-openssl` — only for AES-256-GCM encryption
- `ext-zlib` — only for gzip compression
- `ext-pdo` — only for the database store
- `predis/predis` or `ext-redis` — only for the Redis store

The core installs and runs (Array/File stores) with none of the optional pieces.

## Testing

```sh
composer install
composer test            # service-free unit suite
composer analyse         # PHPStan level 5
composer lint            # php-cs-fixer (dry-run)
```

Use `composer test:kernel`, `composer test:storage`, or `composer test:contract`
to focus on one part of the unit suite. Service checks are separate:

```sh
composer test:integration:redis
composer test:integration:database
```

Redis/MySQL/PostgreSQL integrations skip cleanly when their service is absent
locally. `CACHEER_REQUIRE_REDIS=1` or `CACHEER_REQUIRE_DATABASE=1` makes a selected
required check fail if the service is unavailable. Use dedicated test keyspaces.

## Documentation

- [MIGRATION.md](MIGRATION.md) — v5 → v6 upgrade guide
- [CAPABILITIES.md](CAPABILITIES.md) — capability matrix, guarantees, failure modes
- [WRITING_A_STORE.md](WRITING_A_STORE.md) — third-party store author guide
- [SECURITY.md](SECURITY.md) — support windows and vulnerability reporting
- [KNOWN_LIMITATIONS.md](KNOWN_LIMITATIONS.md) — documented edges
- Usage examples in [`Examples`](Examples)
- [English docs](https://cacheerphp.com/docs/v6/en/getting-started/) and
  [Portuguese docs](https://cacheerphp.com/docs/v6/pt/primeiros-passos/)
- [Release lines and support](https://cacheerphp.com/docs/v6/en/updating/version-support/)
- The incremental v6 plan lives in [ROADMAP.md](ROADMAP.md)

## Contributing

Contributions are welcome — see [CONTRIBUTING.md](CONTRIBUTING.md) and the issue
and pull-request templates. Substantial changes start with an RFC.

## License

CacheerPHP is open-sourced software licensed under the [MIT license](LICENSE).

## Support

If CacheerPHP saves you time, consider supporting the project:

<p>
  <a href="https://buymeacoffee.com/silviooosilva">
    <img src="https://cdn.buymeacoffee.com/buttons/v2/default-yellow.png" height="50" width="210" alt="Buy me a coffee"/>
  </a>
</p>
