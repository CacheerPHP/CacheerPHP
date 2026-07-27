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

## v5 data compatibility

- v5 payloads are **not self-describing**. Reading them requires constructing the
  pipeline with a `V5PayloadReader` that matches the compression/encryption the
  v5 app used.
- v5 used unauthenticated AES-256-**CBC**. A wrong key or tampering surfaces only
  as a failed `unserialize`, never cryptographically. New writes always use the
  authenticated v6 envelope.
- Rewrite-on-read is implemented for `FileStore` and `DatabaseStore`. `RedisStore`
  entries migrate on their next write (legacy reads keep working until then).

## Encryption and compression are opt-in

- The default pipeline does **not** encrypt or compress. Enable AES-256-GCM
  (`ext-openssl`) and gzip (`ext-zlib`) explicitly via `PipelineConfig`. Never
  cache secrets in a store without encryption enabled.

## Observability records metadata only

- Cache **values are never captured** by events, metrics, or the logging
  subscriber by default. `InstrumentedStore` can capture values only when
  explicitly enabled with a redactor — intended for debugging, not production.

## Service matrix coverage

- MySQL/MariaDB and PostgreSQL behavior is verified in CI service jobs; those
  suites **skip locally** when the database is not running. SQLite (in-memory)
  and Redis (via `predis`) cover the database and Redis paths locally.
