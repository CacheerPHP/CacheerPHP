# ADR 0003 — Time is an injected Clock

- Status: Accepted
- Context: v6 rewrite

## Context

Caching is defined by time: TTLs, expiry, stale-while-revalidate windows, lock
leases. v5 called `time()` directly, so time-dependent behavior could only be
tested with real `sleep()`, making the suite slow and flaky and expiry edge
cases hard to pin down.

## Decision

Every component that needs the current time depends on a `Clock` (`now()` and a
float variant). Production uses `SystemClock`; tests use `FakeClock`, which
advances on demand. Stores, decorators, policies, PSR adapters, and locks all
take the clock by injection — no component reads the wall clock directly.

## Consequences

- Expiry, SWR, and lock-lease behavior are tested deterministically with zero
  `sleep()` calls; the suite is fast and stable.
- A store author must thread the clock through instead of calling `time()`,
  which the conformance suite enforces.
- Time can be frozen or fast-forwarded in tests to hit exact boundaries.
