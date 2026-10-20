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
  <strong>One cache API for any PHP application.</strong>
</p>

<p align="center">
  <a href="https://github.com/CacheerPHP/CacheerPHP/releases"><img src="https://img.shields.io/github/release/CacheerPHP/CacheerPHP.svg?style=for-the-badge&color=315dc9" alt="Latest stable release"/></a>
  <img src="https://img.shields.io/packagist/dependency-v/silviooosilva/cacheer-php/PHP?style=for-the-badge&color=315dc9" alt="PHP Version"/>
  <img src="https://img.shields.io/packagist/dt/silviooosilva/cacheer-php?style=for-the-badge&color=315dc9" alt="Downloads"/>
  <a href="https://github.com/silviooosilva"><img src="https://img.shields.io/badge/maintainer-@silviooosilva-315dc9.svg?style=for-the-badge&color=315dc9" alt="Maintainer"/></a>
</p>

---

**Framework-independent PHP caching with scoped invalidation, composable stores, and optional monitoring.** Use the same cache interface in vanilla PHP, Laravel, or a worker. Start with persistent file storage; add Redis, tiered caching, or fallback stores when your application needs them.

PHP **8.3+** is required. File and array stores need no backend clients or optional extensions. Redis and database connections are supplied by your application.

## Run your first cache

Install the stable 6.x line:

```sh
composer require silviooosilva/cacheer-php:"^6.0"
```

Save this as `cache-demo.php` in your project root:

```php
<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Silviooosilva\CacheerPhp\Cacheer;

$catalog = Cacheer::file(__DIR__ . '/var/cache')->scope('catalog');

$products = $catalog->remember('products', 300, static function (): array {
    echo "Loading products from source...\n";

    return [
        ['id' => 1, 'name' => 'Desk lamp'],
        ['id' => 2, 'name' => 'Notebook'],
    ];
});

echo 'Products: ' . count($products) . PHP_EOL;
```

Run `php cache-demo.php` twice. The first run prints the loading message and `Products: 2`. The second prints only `Products: 2`: the callback is skipped because the result is cached for five minutes. The file store persists between processes; `inMemory()` does not.

Keep the cache directory outside the public web root and writable by the PHP process. The [getting-started guide](https://cacheerphp.com/docs/v6/en/getting-started/) continues with a JSON-backed catalog, explicit invalidation, and an application integration path.

## Why choose CacheerPHP?

- **Keep your cache code portable.** Construct a cache in your application bootstrap or Laravel service provider. Inject `Contracts\Cache` into services and keep the same calls across projects.
- **Give invalidation a boundary.** `scope('catalog')->clear()` clears that keyspace. Scopes also qualify tags, counters, and lock names. All four built-in stores support tags and scoped clearing.
- **Compose the storage you need.** Combine L1/L2 stores, circuit-breaker fallback, and TTL policies. Add gzip or authenticated AES-256-GCM encryption through an explicit storage pipeline.
- **See what your cache is doing.** The optional [Cacheer Monitor](https://cacheerphp.com/docs/v6/en/cacheer-monitor/quick-start/) dashboard shows hits, misses, timings, and key activity. Value previews are opt-in.
- **Integrate through familiar contracts.** PSR-16 and PSR-6 adapters are included. The core does not load `.env`, migrate a database, or change the timezone at autoload time.

Using Laravel? Read [CacheerPHP vs Laravel Cache](https://cacheerphp.com/docs/v6/en/guides/cacheerphp-vs-laravel-cache/) to choose the right fit, or [bind CacheerPHP in Laravel](https://cacheerphp.com/docs/v6/en/guides/laravel/). Laravel Cache already provides read-through caching, stale-while-revalidate, locks, and failover; choose CacheerPHP for the boundaries and composition your application needs.

| Your next step | Guide |
| --- | --- |
| Install, run twice, and invalidate a cached result | [Getting started](https://cacheerphp.com/docs/v6/en/getting-started/) |
| Integrate with Laravel's service container | [Laravel integration](https://cacheerphp.com/docs/v6/en/guides/laravel/) |
| Choose file, database, Redis, or in-memory storage | [Stores and capabilities](https://cacheerphp.com/docs/v6/en/api/drivers/) |
| Inspect a real request | [Monitor quick start](https://cacheerphp.com/docs/v6/en/cacheer-monitor/quick-start/) |
| Upgrade an existing v5 application | [Migrating from v5](MIGRATION.md) |

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
  and correctness fixes for 12 months from the 6.0.0 release.

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
