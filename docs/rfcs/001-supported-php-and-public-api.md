# RFC-001: Supported PHP and public API

- Status: Accepted
- Accepted: 2026-07-24
- Milestone: 2

## Motivation

The v5 API combines instance calls, global singleton state, magic delegation,
and backend status flags. V6 needs an API that IDEs and static analysers can
see completely while preserving a small common workflow.

## Proposed API

- PHP 8.3 is the minimum; PHP 8.3, 8.4, and 8.5 are supported for 6.0.
- `Cache` is the instance-first core API.
- Its explicit methods are `entry`, `get`, `set`, `delete`, `clear`, `has`,
  `remember`, `many`, `setMany`, `deleteMany`, and `scope`.
- `ScopedCache` exposes the same cache operations and returns a new object for
  every nested scope.
- The legacy `Cacheer` facade remains outside the kernel during the
  compatibility window. It is not used by kernel control flow.
- New public types remain under `Silviooosilva\CacheerPhp`.

## Rejected alternatives

- Keeping `__call()` and PHPDoc-only methods was rejected because it hides
  mistakes until runtime.
- A required service container was rejected because the package is a library.
- A global default cache was rejected because it creates shared mutable state.

## Compatibility impact

Raising the PHP baseline is a major-version change. The v5 facade remains
available while adapters and migration tooling are built in later milestones.

## Security impact

An explicit API reduces accidental invocation and makes validation paths
auditable. This RFC does not define persisted-value security.

## Contract tests

Kernel reflection tests require explicit methods, no magic delegation, no
static properties, and immutable scoped cache objects.
