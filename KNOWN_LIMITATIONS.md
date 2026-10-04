# Known limitations

Honest edges of CacheerPHP 6.0. None of these are bugs; they are documented
trade-offs so you can design around them. Failure *modes* per capability are in
[CAPABILITIES.md](CAPABILITIES.md).

## Scope and tag invalidation on some stores

- On `FileStore`, scope and tag invalidation are **scan-based**: clearing a scope
  or a tag walks the store's own keyspace. This is O(entries), not O(1). It stays
  confined to the configured directory and never touches unrelated data.
- Tag indexes are **best-effort metadata**. A key that expires before its tag is
  flushed is a no-op, not an error. Tags are for grouped invalidation, not for
  enumerating a guaranteed-complete set.
- Tag membership survives expiry, so the tag index of a key that expires and is
  never written, deleted, or flushed again keeps a small record until the next
  `clear()` or `clearTag()`.
- `DatabaseStore` stores scopes and tags in 255-character columns, so it rejects
  a scope path (segments joined by `/`) or a tag longer than 255 characters
  before writing — `InvalidScopeException` for scopes, `InvalidArgumentException`
  for tags. A scoped tag includes its scope, so it counts toward the limit. The
  limit applies on every driver, including SQLite. Lock names have no limit.

## Atomicity depends on the backend

- `ArrayStore` counters and CAS are atomic **within one process only** — it is a
  per-request cache, not shared state.
- `FileStore` serializes atomic operations with a per-key file lock (safe across
  processes on one host, not across hosts).
- Cross-host atomicity and locking require a shared backend (`DatabaseStore` or
  `RedisStore`). The kernel throws `UnsupportedCapabilityException` rather than
  faking a guarantee a store cannot make.

## Stale-while-revalidate needs a live value

- `flexible()` serves a stale value only while one is still stored. It composes
  with a hard TTL of `stale`; once the entry is gone the next call recomputes
  synchronously. Deferred refresh runs through the configured executor — the
  synchronous default refreshes in-process, so a true "after response" refresh
  requires wiring an appropriate `DeferredExecutor`.

## v5 cached data is not read

- v6 does not read entries written by v5: the storage layouts and payload formats
  differ, so an upgrade starts with a cold cache in a new keyspace. See
  [MIGRATION.md §5](MIGRATION.md#5-cached-data-v6-starts-cold).

## Encryption and compression are opt-in

- The default pipeline does **not** encrypt or compress. Enable AES-256-GCM
  (`ext-openssl`) and gzip (`ext-zlib`) explicitly via `PipelineConfig`. Never
  cache secrets in a store without encryption enabled.
- Turning encryption on for an existing cache requires clearing it (or a new
  keyspace): an encrypting pipeline refuses the old plaintext entries with
  `UnsupportedEnvelopeException` instead of treating them as trusted.
- Without encryption the backend is trusted. `PhpSerializer` allows every class
  by default, so anyone able to write to a shared backend can plant objects that
  are unserialized on read; restrict it with `new PhpSerializer(allowedClasses:
  false)` or enable encryption.

## Observability records metadata only

- Cache **values are never captured** by events, metrics, or the logging
  subscriber by default. `InstrumentedStore` can capture values only when
  explicitly enabled with a redactor — intended for debugging, not production.
- Telemetry is best-effort: a failing listener or dispatcher is ignored rather
  than reported, so it never changes a cache operation's outcome.
- An instrumented native `deleteMany()` reports one result for the whole batch,
  so each per-key delete event carries that shared result.

## Service matrix coverage

- MySQL/MariaDB and PostgreSQL behavior is verified in CI service jobs; those
  suites **skip locally** when the database is not running. SQLite (in-memory)
  and Redis (via `predis`) cover the database and Redis paths locally.
