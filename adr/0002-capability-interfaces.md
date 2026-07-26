# ADR 0002 — Capabilities as interfaces, not a fat Store contract

- Status: Accepted
- Context: v6 rewrite

## Context

Not every backend can honor every operation. A file cache locks differently from
Redis; an atomic counter is trivial on Redis but needs a row lock on SQL and is
process-local for an array store. A single wide interface forces every driver to
either implement operations it cannot guarantee or throw from methods callers
assume work.

## Decision

The `Store` contract is minimal — `get`, `set`, `delete`, `clear`. Everything
else is an **optional capability interface** (`BatchStore`, `TaggableStore`,
`AtomicStore`, `LockingStore`, …). The kernel checks `instanceof` and throws a
typed `UnsupportedCapabilityException` when a capability is missing, rather than
silently degrading.

## Consequences

- A custom store is small: implement four methods, add capabilities as you can
  actually guarantee them.
- The capability matrix documents only proven guarantees; the conformance suite
  skips capability blocks a store does not declare.
- Decorators forward the capabilities their wrapped store provides, so
  composition never loses a feature.
- The cost is capability checks in the kernel and honest "not supported" errors,
  which we prefer to silent, surprising fallbacks.
