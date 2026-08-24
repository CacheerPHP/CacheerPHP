# Writing a CacheerPHP store

You can back CacheerPHP with any storage engine without touching the kernel or
reading the built-in store source. Implement the four-method
[`Store`](src/Contracts/Store.php) contract, add capability interfaces for
anything extra you support, and prove it with the shared conformance suite.

## 1. The minimal store

```php
use Silviooosilva\CacheerPhp\Contracts\Store;
use Silviooosilva\CacheerPhp\Contracts\Clock;
use Silviooosilva\CacheerPhp\Kernel\{CacheEntry, Key, Ttl};

final class MyStore implements Store
{
    public function __construct(private readonly Clock $clock) {}

    public function get(Key $key): CacheEntry
    {
        // ... look up by $key->identity(); return a miss or a hit.
        return CacheEntry::miss($key);
        // On a live hit:
        // return CacheEntry::hit($key, $value, $createdAt, $expiresAt); // $expiresAt null = forever
    }

    public function set(Key $key, mixed $value, Ttl $ttl): void
    {
        $expiresAt = $ttl->expiresAt($this->clock); // null = forever
        // ... persist.
    }

    public function delete(Key $key): bool { /* true if something was removed */ }

    public function clear(): void { /* only your configured keyspace */ }
}
```

Contract rules the conformance suite enforces:

- **Time comes from the injected `Clock`.** Never call `time()` directly —
  that is what makes behavior testable with `FakeClock`.
- **Expiry is lazy.** `get()` on an expired entry returns a miss.
- **Values round-trip losslessly**, including `false`, `0`, `''`, `[]`, and
  nested arrays/objects. Use the storage pipeline (see below) rather than
  bespoke serialization.
- **`clear()` and any scan stay inside your keyspace** (prefix/table/directory).
  Never touch unrelated data in a shared backend.

## 2. Add capabilities you actually provide

Implement only the interfaces you can honor; the kernel throws
`UnsupportedCapabilityException` for the rest instead of degrading silently.

**Never answer "can this store do X?" with `instanceof`.** A decorator has to
declare every capability it might forward, so `instanceof` is true for wrappers
around stores that cannot. Ask `Capabilities::supports($store, X::class)` (or
`$cache->supports(X::class)`) instead. If your store is itself a decorator,
implement `CapabilityAware::supports()` and delegate to whichever store will run
the operation — that is the whole contract, and the kernel relies on it to
degrade optional optimizations rather than fail.

| Interface | Adds |
|---|---|
| `BatchStore` | `getMany` / `setMany` / `deleteMany` |
| `TouchStore` | extend an entry's TTL in place |
| `PrunableStore` | remove expired entries |
| `InspectableStore` | iterate entries / read metadata |
| `FlushableScopeStore` | clear a single scope |
| `TaggableStore` | tag keys and invalidate by tag |
| `AtomicStore` | atomic `increment` / `compareAndSwap` |
| `LockingStore` | named locks (`acquire` / `block` / `release`) |

## 3. Reuse the storage pipeline

Persistent stores should encode values through
[`EnvelopeCodec`](src/Storage/EnvelopeCodec.php), obtained from a typed
[`PipelineConfig`](src/Config/PipelineConfig.php). You get serialization,
optional compression, authenticated encryption, size limits, and v5
rewrite-on-read for free:

```php
$codec = PipelineConfig::default()->withGzip()->codec();
$blob  = $codec->encode($value);   // versioned envelope
$value = $codec->decode($blob);    // typed failure on tampering/over-limit
```

## 4. Prove it with the conformance suite

Extend [`Tests\Support\StoreConformance`](tests/Support/StoreConformance.php) and
return your store from `createStore()`. It runs the full base contract plus every
capability block your store declares, and skips the blocks you don't:

```php
use Tests\Support\{FakeClock, StoreConformance};
use Silviooosilva\CacheerPhp\Contracts\Store;

final class MyStoreConformanceTest extends StoreConformance
{
    protected function createStore(FakeClock $clock): Store
    {
        return new MyStore($clock);
    }
}
```

If it passes, your store is a first-class CacheerPHP driver: it composes with
`Cacheer`, scopes, tiering, resilience, the PSR-16/PSR-6 adapters, and the CLI
exactly like the built-in stores.

## 5. Getting it listed as compatible

A community adapter is listed as compatible when it: passes the conformance suite
in CI on supported PHP versions; documents which capabilities it provides and
their guarantees; and documents its failure modes (connection loss, timeouts).
See the driver-proposal issue template.
