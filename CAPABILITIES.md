# Capability matrix

v6 keeps the [`Store`](src/Contracts/Store.php) contract minimal — `get`, `set`,
`delete`, `clear`. Everything else is an **optional capability** a store declares
by implementing an interface. The kernel checks for the interface and throws
[`UnsupportedCapabilityException`](src/Exceptions/UnsupportedCapabilityException.php)
rather than silently degrading. This table lists only guarantees proven by the
shared conformance suite and integration tests.

| Capability | Interface | Array | File | Database | Redis |
|---|---|:--:|:--:|:--:|:--:|
| Core get/set/delete/clear | `Store` | ✅ | ✅ | ✅ | ✅ |
| Batch get/set/delete | `BatchStore` | ✅ | ✅ | ✅ | ✅ |
| Extend TTL in place | `TouchStore` | ✅ | ✅ | ✅ | ✅ |
| Remove expired entries | `PrunableStore` | ✅ | ✅ | ✅ | ✅ |
| List entries / metadata | `InspectableStore` | ✅ | ✅ | ✅ | ✅ |
| Clear one scope | `FlushableScopeStore` | ✅ | ✅ | ✅ | ✅ |
| Tag + invalidate by tag | `TaggableStore` | ✅ | ✅ | ✅ | ✅ |
| Atomic counter / CAS | `AtomicStore` | ✅ | ✅ | ✅ | ✅ |
| Named locks | `LockingStore` | ✅ | ✅ | ✅ | ✅ |

Decorators (`TieredStore`, `ResilientStore`, `InstrumentedStore`) forward every
capability their wrapped store(s) provide, so composition never loses a feature.

## Guarantees and failure modes

**Core.** `clear()` only affects this store's configured keyspace (directory,
table, or key prefix) — never anything else in the same backend. `get()` on an
expired entry is a miss and lazily removes the entry.

**Atomic (`increment` / `compareAndSwap`).**
- *Array*: atomic within one process; not shared across processes.
- *File*: serialized by a per-key file lock; safe across processes on one host.
- *Database*: row-locked read-modify-write (`FOR UPDATE` where supported;
  serialized transactions on SQLite).
- *Redis*: server-side atomic operations.
- *Failure*: incrementing a non-integer value throws
  [`StoreOperationFailedException`](src/Exceptions/StoreOperationFailedException.php).

**Locks (`LockingStore`).** `acquire()` is non-blocking; `block($seconds)` waits
up to a bound. Release is compare-and-delete (a lock only deletes its own token),
so a slow holder cannot release a lock another worker has since acquired. Locks
carry a TTL and self-expire, so a crashed holder never deadlocks the keyspace.

**Tags (`TaggableStore`).** Tagging associates already-stored keys with a tag;
`clearTag()` invalidates them. Tag indexes are best-effort metadata: a key that
expires before its tag is flushed is simply a no-op, never an error.

**Scopes (`FlushableScopeStore`).** `scope('x')->clear()` removes only that scope.
Clearing the root scope clears the whole store. Scoping the same key name into
different scopes yields independent entries.

**Serve-stale / resilience.**
- `flexible()` serves a fresh value within the fresh window, a stale value while a
  single worker refreshes (deferred), and recomputes synchronously past the stale
  window. A hard TTL of `stale` must still hold the value.
- `ResilientStore` serves from a fallback when the primary's circuit breaker is
  open; it fails closed (miss), never returning stale or wrong data.

**Storage pipeline.** Values are serialized → optionally compressed → optionally
authenticated-encrypted (AES-256-GCM) into a versioned envelope. Decoding is
deterministic and typed: an over-limit, unauthenticated, or unrecognized blob
raises a typed exception rather than returning corrupt data. See
[MIGRATION.md](MIGRATION.md#5-data-compatibility-and-rewrite-on-read) for reading
v5 payloads.

## Writing your own store

A custom store only needs the four `Store` methods; add capability interfaces as
you implement them. The reusable conformance suite verifies your store behaves
like the built-ins — see [WRITING_A_STORE.md](WRITING_A_STORE.md).
