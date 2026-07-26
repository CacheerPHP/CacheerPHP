# ADR 0001 — Instance-first kernel over a static facade

- Status: Accepted
- Context: v6 rewrite

## Context

v5's primary entry point was a `Cacheer` object that also supported static-style
access, carried mutable success/message state, and read configuration from the
environment at construction. This made behavior implicit (global state, ambient
`.env`), hard to test deterministically, and awkward to run two differently
configured caches in one process.

## Decision

The v6 core is **instance-first**. A `Cache` is constructed explicitly from a
`Store` (and optional `Clock`, executor, and event dispatcher). There is no
global state and no autoload-time side effect. Static/global convenience, and the
old method surface, live only in the opt-in `LegacyCacheer` bridge, which is a
migration aid and not part of the core.

## Consequences

- Multiple independent caches coexist trivially; tests construct exactly what
  they assert against.
- Configuration is passed in, not discovered, so there are no hidden environment
  or timezone side effects.
- v5 users get a bridge for a staged migration; new code depends on `Cache`.
- The cost is more explicit wiring at the call site, which we consider a feature.
