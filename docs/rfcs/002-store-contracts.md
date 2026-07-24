# RFC-002: Store contracts

- Status: Accepted
- Accepted: 2026-07-24
- Milestone: 2

## Motivation

The v5 store interface requires every backend to imitate every feature and
communicate through mutable success/message state. Custom v6 stores need a
small honest contract with optional guarantees.

## Proposed API

`Store` contains only:

```php
get(Key $key): CacheEntry
set(Key $key, mixed $value, Ttl $ttl): void
delete(Key $key): bool
clear(): void
```

Accepted optional capabilities are `BatchStore`, `TaggableStore`,
`LockingStore`, `AtomicStore`, `TouchStore`, `PrunableStore`,
`InspectableStore`, and `FlushableScopeStore`.

- High-level batch operations use `BatchStore` when present and otherwise use
  the safe base-store fallback.
- Scoped clear requires `FlushableScopeStore`; it never clears the entire
  backend as a fallback.
- Atomic operations and locks never receive best-effort emulation.
- Backend failures are thrown. Mutable `isSuccess()` and `getMessage()` state
  is not part of v6 control flow.

## Rejected alternatives

- A giant interface was rejected because it encourages false guarantees.
- Boolean status plus a separate error message was rejected because it loses
  the cause and is unsafe under concurrent use.
- Silent capability degradation was rejected for destructive or atomic work.

## Compatibility impact

V5 stores need adapters before they can be consumed as v6 stores. New stores
can implement only `Store` and add capabilities deliberately.

## Security impact

Explicit keyspace clear and scope-clear boundaries reduce accidental broad
deletion. Original backend exceptions remain attached for safe diagnosis.

## Contract tests

The ArrayStore reference tests cover hit/miss, cached `null`, expiration,
batch ordering, deletion, touch, pruning, inspection, and nested scope clear.
Fallback and unsupported-capability behavior is tested at the `Cache` layer.
