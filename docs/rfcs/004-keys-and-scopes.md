# RFC-004: Keys and scopes

- Status: Accepted
- Accepted: 2026-07-24
- Milestone: 2

## Motivation

Keys and namespace strings were previously combined ad hoc. V6 needs
collision-free logical identity and immutable scope boundaries before backend
encoding is introduced.

## Proposed API

- `Key` is non-empty, at most 1024 bytes, and rejects control characters.
- A key contains its raw value and a typed `Scope`.
- `Scope` is an immutable ordered list of at most 64 segments.
- Each segment is non-empty, at most 255 bytes, and rejects `/` and control
  characters.
- `Cache::scope()` and `ScopedCache::scope()` append scopes and return new
  cache views.
- Internal identity uses length-prefixed components to avoid collisions.
- Root `clear()` clears only the store's configured keyspace.
- Scoped `clear()` clears the selected scope and descendants only, and
  requires `FlushableScopeStore`.
- Backend-safe hashing/encoding and generation invalidation are finalized in
  the storage-pipeline and built-in-store milestones.

## Rejected alternatives

- String concatenation with `:` was rejected because user keys can collide.
- Mutable namespace state was rejected because reused cache objects can leak
  operations into the wrong tenant.
- Falling back from scoped clear to store clear was rejected as unsafe.

## Compatibility impact

V5 namespace strings need explicit mapping in the compatibility bridge.
Logical key values remain readable and are not backend-encoded in this RFC.

## Security impact

Control-character and size validation reduces log injection and resource
abuse. Explicit scope boundaries reduce cross-tenant deletion risk.

## Contract tests

Tests cover invalid keys/scopes, collision-resistant identity, immutable
nesting, root isolation, descendant clearing, and unsupported scoped clear.
