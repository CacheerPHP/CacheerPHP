# RFC-003: TTL and time

- Status: Accepted
- Accepted: 2026-07-24
- Milestone: 2

## Motivation

TTL behavior must be consistent, deterministic in tests, and independent from
global timezone settings or direct wall-clock calls.

## Proposed API

- `Ttl` is an immutable normalized duration.
- Accepted inputs are a positive integer number of seconds, `DateInterval`
  without years/months, one-unit strings such as `10 minutes`, `forever`, or
  `null` for forever.
- Zero and negative TTLs throw `InvalidTtlException`.
- `Ttl::until()` converts an absolute expiration using an injected `Clock`.
- Forever is represented by `null`, never large integer arithmetic.
- Expiration overflow throws `InvalidTtlException`.
- An entry expires when `expiresAt <= Clock::now()`.
- Core stores receive a `Clock`; core expiration behavior never calls
  `time()`, `microtime()`, or `sleep()` directly.

## Rejected alternatives

- `strtotime()` parsing was rejected because it depends on ambient time and
  accepts ambiguous phrases.
- Treating non-positive TTL as delete was rejected because it hides mistakes.
- `PHP_INT_MAX` forever TTL was rejected because addition can overflow.

## Compatibility impact

V5 TTL behavior remains in the compatibility layer. V6 callers receive a
precise exception for ambiguous or unsupported inputs.

## Security impact

Bounded parsing prevents unexpectedly long retention caused by ambiguous
input. This RFC does not define serialization or encryption timestamps.

## Contract tests

Tests cover every accepted input form, zero/negative rejection, injected-clock
absolute expiry, remaining TTL, touch, and deterministic expiration.
