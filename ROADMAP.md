# CacheerPHP — ROADMAP 2.0

> Finish 6.x through small, useful changes to the framework we already have.

**Status:** proposed work. No implementation is included in this roadmap update.
**Updated:** 2026-10-08. **Starting point:** `6.x`, commit `4a060b0ea96c`.

The priority is reliable everyday caching: correct scopes, isolated data, safe
writes, predictable expiration, and integrations that preserve cache behavior.
Keep the current architecture and finish these guarantees before adding more API.

## What stays

- `Cacheer` and immutable scope/policy views.
- The four-method `Store` contract and optional capability interfaces.
- Array, File, Redis, and PDO stores.
- The existing storage pipeline, policies, decorators, PSR adapters, and Monitor.
- Explicit configuration and optional backend dependencies.

## How we deliver

1. Choose one problem and reproduce it in the existing tests.
2. Make the smallest fix that preserves the surrounding behavior.
3. Run the relevant tests; use a real backend when its behavior matters.
4. Update only the documentation affected by that fix.
5. Review the result before selecting the next change.

Each work item can contain several small patches. Completing an item means its
stated behavior is verified, not that every related component was redesigned.
New public methods, format changes, and capability removals need a separate,
explicit decision. A proposed implementation below does not settle those decisions.

No benchmark suite, timing budgets, scheduled performance jobs, or repository-wide
cleanup is part of this plan. Add tooling only when a selected fix needs it.

## Evidence we already have

The local checks on 2026-10-04 passed **347 core tests / 1,377 assertions** and
**26 SQLite tests / 77 assertions**. These counts describe the starting point;
passing them does not resolve the uncovered failures below.

The earlier review reproduced scoped-operation failures, SQL scope leakage,
ignored lock timeouts, inconsistent fallback results, missing decode limits,
expiry errors, duplicate refreshes, deferred-item errors, and telemetry side effects.
Those findings apply to the restored starting code. They are not completed fixes.
Live Redis/MySQL/PostgreSQL behavior still needs verification for the relevant
work item. Do not treat a configured CI job as a passing result.

## First: fix correctness in the current API

### R2-01 — Apply scope once

**Problem:** compound operations qualify a key and then pass it through helpers
that qualify it again. Scoped `remember`, `add`, and `pull` can use the wrong key.

- [x] Separate public key qualification from reads/deletes of an already-qualified key.
- [x] Cover nested views and typed scoped keys, including cached `null`.
- [x] Check the same internal path in flexible caching and stale-on-error.

**Done when:** two cold scoped `remember` calls compute once; `add` preserves an
existing entry; `pull` returns and removes it; sibling scopes remain independent.

Start in [CacheOperations](src/Core/CacheOperations.php). Keep this first patch
focused on scope handling; it does not need a new public API.

**Status (2026-10-04): done.** `CacheOperations` now qualifies a key once and
uses private `read()`/`remove()` for already-qualified keys. Verified by
scoped-compound cases in [CacheTest](tests/Kernel/CacheTest.php)
(16 cases, locking and non-locking stores; all failed before the fix). Default
suite 363 passed, SQLite 26 passed, PHPStan clean.

### R2-02 — Keep every operation inside its keyspace

**Problem:** SQL scope patterns can treat `%` and `_` as wildcards. Redis prefixes
are inserted into scan patterns. Accepted names can exceed backend column limits.

- [x] Fix literal SQL scope matching first, with neighboring-tenant regression cases.
- [x] Check Redis glob characters and overlapping prefixes in a separate patch.
- [x] Validate unsupported names/lengths before writes and document any rejection.
- [ ] Verify case, Unicode, and nested-scope behavior on each supported SQL engine.

**Done when:** clearing or inspecting one scope/prefix cannot reach unrelated data.
Prefer escaping or targeted validation over replacing every existing namespace.

Start in [DatabaseStore](src/Stores/DatabaseStore.php), then
[RedisStore](src/Stores/RedisStore.php).

**Status (2026-10-04): in progress.** Literal SQL matching (explicit `ESCAPE`),
Redis prefix escaping + reserved-segment rejection, and exact case/Unicode
matching for scopes and tags (SQLite `instr`, MySQL `utf8mb4_bin` recheck) are
done. Verified by new `StoreConformance` cases and
prefix-isolation cases in [RedisStoreConformanceTest](tests/Integration/Redis/RedisStoreConformanceTest.php)
on Array/File/Tiered/Resilient/Instrumented, SQLite, MySQL 9.3, and Redis.
PostgreSQL not yet run (CI). Length validation done: `DatabaseStore` rejects
scopes/tags over 255 characters before writing on every driver (previously
MySQL non-strict truncated silently), and lock names are hashed, so they are
case-exact and unbounded on MySQL. Remaining: run the case/Unicode cases on
PostgreSQL to close the last checkbox.

### R2-03 — Stop protected work when the lock was not acquired

**Problem:** File and Redis atomic helpers ignore the result of `block()`.
Compound operations can also take an unlocked path after waiting unsuccessfully.

- [x] Check acquisition before changing a protected entry or running protected work.
- [x] Define a consistent timeout result using the existing exception conventions.
- [x] Test owner-safe release and cleanup after a callback fails.
- [x] Add a small shared-worker regression for the affected backend operation.

**Done when:** a held lock prevents a second protected mutation; a timeout never
reports an atomic success; an old owner cannot release a replacement lease.
Document File locks as process-held `flock` locks. Their written TTL does not
expire a live handle. Configurable wait/lease APIs can be considered separately.

Start in [FileStore](src/Stores/FileStore.php),
[RedisStore](src/Stores/RedisStore.php), and their lock helpers.

**Status (2026-10-04): done.** File/Redis atomic helpers and `add()` throw
`StoreOperationFailedException` on lock timeout and never mutate; `remember()`
keeps its documented degrade but re-reads first; Redis lock no longer drops
ownership on a repeated `acquire()`. Verified by new `StoreConformance` lock
cases (hooks overridden in the File/Redis main tests) and `CacheTest` timeout
cases. Default suite 455 passed; SQLite, MySQL 9.3, Redis green. File `flock`
semantics documented in CAPABILITIES and the docs guide (en/pt).

### R2-04 — Enforce storage read policy and configured limits

**Problem:** size limits are not enforced consistently on plain and legacy reads.
An encryption-configured codec can accept an envelope that selects plaintext.

- [x] Enforce configured limits before deserialization on every supported read path.
- [x] Reject invalid limits and malformed envelopes with defined failures.
- [x] Define whether encryption configuration requires encrypted reads; make
  permitted legacy/plaintext compatibility deliberate and documented.
- [x] Add focused oversized, truncated, tampered, and downgrade regression cases.

**Done when:** plain, compressed, encrypted, and enabled legacy paths honor the
same configured value limit, and input cannot bypass the chosen read policy.
These fixes do not automatically authorize a new envelope or key derivation.

Start in [EnvelopeCodec](src/Storage/EnvelopeCodec.php).

**Scope change (2026-10-04):** `V5PayloadReader` and rewrite-on-read were removed
(decided with the maintainer). They only ran on records already in v6 layout, so
real v5 data was never read, and a configured reader let any non-envelope blob
bypass the encrypted read path into an unrestricted `unserialize()`. Every
non-envelope blob is now rejected, so the "legacy" read paths above no longer
exist; R2-04 covers the envelope paths only.

**Status (2026-10-04): done.** Decision: an encrypting pipeline reads only
encrypted envelopes (`UnsupportedEnvelopeException::unencrypted()`); no plaintext
compatibility mode — enabling encryption means clearing the cache or using a new
keyspace (documented). The value limit is checked on the final payload before
`unserialize()` on every pipeline; negative limits throw; a magic-only envelope is
`CorruptedPayloadException`. Also documented: without encryption the backend is
trusted (`PhpSerializer` allows all classes by default). Regressions live in
`CompressionAndLimitsTest`, `EncryptionTest`, and `EnvelopeCodecTest`. Default
suite 464 passed; SQLite, MySQL, Redis green.

### R2-05 — Make expiration and forever behave consistently

**Problem:** database `touch` can renew an expired entry, negative-cache policy
can shorten explicit forever storage, and promotion can change an entry's age.

- [x] Make `touch` return a miss for expired entries.
- [x] Preserve explicit forever behavior under policies, including empty values.
- [x] Preserve original creation time and absolute expiry on touch and promotion.
- [x] Test arithmetic boundaries before changing TTLs or counters.

**Done when:** expired data stays expired, forever remains explicit, and a value's
freshness does not restart when it moves between cache layers. Boundary failures
leave the previous value intact. Handle backend counter/transaction races in
separate patches with real-backend evidence.

Start in [DatabaseStore](src/Stores/DatabaseStore.php),
[CacheOperations](src/Core/CacheOperations.php), and [Ttl](src/Kernel/Ttl.php).

**Status (2026-10-04): done.** Database `touch()` only updates unexpired rows.
Negative caching only shortens: explicit forever stays forever and a shorter
explicit TTL is kept (also fixes it lengthening `set($k, null, 5)` to the
negative TTL). `TieredStore` stores L1 copies as an internal record carrying the
original `createdAt` and true `expiresAt`, so promotion keeps age and expiry
(including through `flexible()` across workers). Counter overflow throws on every
store via `Stores\Support\CounterArithmetic` and keeps the value; a TTL+grace
overflow is `InvalidTtlException`. Backend counter/transaction races remain a
separate patch. Default suite 480 passed; SQLite, MySQL, Redis green.

### R2-06 — Remove obsolete tag state

**Problem:** clearing Array entries leaves tag membership that can later delete
an unrelated, untagged entry written under the same key.

- [x] Clear Array tag state with its entries.
- [x] Cover delete, expiry, scope clear, and key reuse.
- [x] Agree overwrite/retag behavior and check the persistent stores separately.

**Done when:** an old tag cannot invalidate a new untagged entry after clearing
or deleting the old entry. Begin with [ArrayStore](src/Stores/ArrayStore.php);
changing persistent tag indexes is a separate decision.

**Status (2026-10-04): done (full fix approved by the maintainer).** Agreed
semantics: membership ends with the entry (delete/deleteMany/clear/clearScope/
clearTag) and survives overwrite and expiry; tagging a missing key is a no-op.
Probe showed File, SQLite, MySQL, and Redis leaked on delete, clearScope, and
tag-missing. Fixes: ArrayStore reverse map; DatabaseStore `_tags` cleanup in the
same transaction; FileStore `keytags/` sidecar index; RedisStore `:kt:` sets
maintained by one Lua script (new reserved `:kt` segment). Cases live in
`StoreConformance` for every store. phpredis adapter not exercised (R2-11).

## Then: make existing integrations dependable

### R2-07 — Keep monitoring from changing cache behavior

**Problem:** instrumented batches can lose transaction behavior; listener failures
can make completed operations report failure; reads can increase `bytes_written`.

- [x] Forward native batch calls through InstrumentedStore, preserving rollback.
- [x] Isolate telemetry listener failures at the cache boundary.
- [x] Correct operation/byte counters without changing the event model wholesale.
- [x] Check the current Monitor bridge for each changed event behavior.

**Done when:** the monitored and unmonitored cache have the same result and stored
state; a throwing listener cannot undo a successful write; reads do not count as
bytes written. Values remain opt-in, and errors must not expose credentials.

Start in [InstrumentedStore](src/Stores/InstrumentedStore.php) and
[Observability](src/Observability).

**Status (2026-10-04): done.** Native batch forwarding (SQLite rollback proven
monitored vs unmonitored); every dispatch goes through internal `SafeDispatch`;
`bytes_written` counts writes only. Monitor bridge checked: per-key batch events
map as before, `size_bytes` is only a last-seen size; the bridge now redacts URI
credentials and password/secret/token/auth values from error messages
(cacheer-monitor commit). Event model unchanged.

### R2-08 — Respect refresh windows and avoid duplicate pending work

**Problem:** repeated stale reads can queue repeated refreshes, and flexible
caching can serve a value beyond the caller's requested stale window.

- [x] Enforce the requested fresh/stale boundaries against original creation time.
- [x] Deduplicate pending refreshes and recheck freshness under the existing lock.
- [x] Release pending state and locks when scheduling or callbacks fail.
- [x] Document the synchronous default and explicit flushing in workers.

**Done when:** an eligible stale burst schedules one pending refresh; a completed
refresh prevents unnecessary queued work; values beyond the hard stale boundary
recompute synchronously. Lease expiry still limits single-flight guarantees.

Start in [CacheOperations](src/Core/CacheOperations.php) and
[AfterResponseDeferredExecutor](src/Support/AfterResponseDeferredExecutor.php).

**Status (2026-10-04): done.** The refresh lock is taken at schedule time and
held by the task as the "pending" marker (released in `finally`, or at once if
`defer()` throws), so a stale burst queues one refresh; tasks re-check freshness
before computing; hits older than `stale` recompute synchronously and the
single-flight re-read respects the same boundary. No new API or state. Worker
flushing documented (en/pt). Default suite 524 passed.

### R2-09 — Correct PSR adapter edge cases

**Problem:** a newly assigned deferred PSR-6 item can read as `null`, and invalid
native keys do not always become the required PSR exceptions.

- [x] Return the assigned deferred value before commit.
- [x] Cover expiry while deferred, mutation, and save/delete/clear interactions.
- [x] Map invalid keys consistently at each PSR adapter boundary.
- [x] Check failure returns and value fidelity for the supported serializer setup.

**Done when:** deferred visibility and expiry follow the adapter contract,
invalid inputs produce the proper PSR exception, and native behavior is preserved.
Do not expand these fixes into new adapter APIs.

Start in [Psr6Item](src/Psr/Psr6Item.php), [Psr6Pool](src/Psr/Psr6Pool.php), and
[Psr16Cache](src/Psr/Psr16Cache.php).

**Status (2026-10-04): done.** Deferred items are queued as copies with expiry
pinned at `saveDeferred()` and read back as hits until then; both adapters apply
PSR + native key rules (non-string keys rejected; ints allowed in PSR-16 for
numeric array keys) and map `InvalidKeyException`/`InvalidTtlException` to the
PSR exception; writes return `false` on store failure, reads keep throwing.
Falsy values round-trip exactly through a FileStore pipeline. No new API.

### R2-10 — Settle tiered and resilient guarantees before calling them stable

**Problem:** a worker can retain stale L1 data; independently mirrored counters
and CAS can return conflicting results; fallback recovery can resurrect old data.

- [x] Reproduce each decorator failure independently.
- [x] Choose and document the L1 freshness bound and partial-failure behavior.
- [x] Decide which store's result is authoritative during resilient writes.
- [x] Resolve atomic/locking capability claims across failover and define recovery
  after outage writes before changing interfaces or adding recovery APIs.
- [x] Keep programming/corruption failures distinct from backend availability.

**Done when:** tests and capability documentation agree on stale-read bounds,
partial writes, atomicity, and recovery. If a feature cannot provide its claimed
guarantee, explicitly narrow or defer that support rather than publish the claim.
Implement Tiered and Resilient changes as separate, reviewed patches.

Start in [TieredStore](src/Stores/TieredStore.php),
[ResilientStore](src/Stores/ResilientStore.php), and
[CAPABILITIES.md](CAPABILITIES.md).

**Status (2026-10-04): done, as two patches.** Tiered: default L1 cap of 60s
bounds cross-worker single-key staleness (`Ttl::forever()` opts out); L2-first
write order documented and tested. Resilient: outage-only failover
(`BackendFailure`), primary-authoritative writes with a best-effort mirror,
counters/CAS/locks primary-only and fail-closed (`FailClosedLock`), and in-process
outage-write reconciliation on recovery (limit 1,000, else clear). No interface
changes or recovery APIs; per-process limits documented in KNOWN_LIMITATIONS.

## Finally: prepare a usable release

### R2-11 — Use the existing checks honestly

- [x] Fix Redis cleanup so an unavailable local service skips without teardown errors.
- [x] Make an unavailable required CI service fail instead of producing a green skip.
- [x] Run affected integration tests on the backend/client actually advertised.
- [x] Add contention or fault cases only for the guarantee being fixed.
- [x] Check supported PHP/dependency configurations through the existing CI jobs.

**Done when:** local skips are clean, required checks exercise their backend, and
release evidence identifies what ran. Extra platforms, services, and workflow
matrices are added only to support a specific accepted compatibility claim.

**Status (2026-10-04): done.** Redis tests connect through the `RedisConnection`
interface chosen by `REDIS_CLIENT`/`REDIS_DB` and clean up only after a connection
was made; `StoreConformance::serviceUnavailable()` skips locally and fails when
`CACHEER_REQUIRE_REDIS`/`CACHEER_REQUIRE_DATABASE` is set (both flags were set in
CI but never read). CI's Redis job gains one phpredis entry for the shipped
`PhpRedisConnection` (not runnable locally: no ext-redis). Contention/fault cases
were added with R2-03/05/06/07/10. Evidence: lowest dependency set (psr/* minimums,
Pest 4.0.0, Predis 2.3.0) passes the full unit suite locally on PHP 8.4; its two
advisories are dev-only (phpunit, symfony/process); the current set audits clean.
Remaining evidence that needs CI: PHP 8.3/8.5 matrix, PostgreSQL, phpredis.

### R2-12 — Align installation, migration, and docs with the shipped code

- [x] Check Composer stable-release constraints, support links, CLI installation,
  license presence, and a clean consumer installation.
- [x] Remove commands or examples that reference missing features; verify changed
  examples and keep English/Portuguese instructions consistent.
  (2026-10-04: v5 examples removed, v6 examples promoted to `Examples/` and all
  24 run; `test:concurrency`, `npm run`, `.env.example` references removed.)
- [x] Verify the v5 migration claim against an actual tagged v5 installation.
  Document a separate cold keyspace if backend layouts are incompatible.
- [x] Make rollback instructions explicit; a legacy payload reader alone does not
  import old file paths, SQL schemas, or Redis layouts.
- [x] Explain how a custom-store author can run conformance tests from their own
  project; do not promise dependency `autoload-dev` is available.
- [x] Keep release labels, known limitations, and the changelog accurate.

**Done when:** a consumer can install, use the documented API/CLI, migrate through
the supported path, and understand the remaining limits. Change individual guides
as their related fixes land. A new tooling package or migration importer is optional.

**Status (2026-10-08): stable release files prepared.** Every v6 install line uses
`"^6.0"`. Framework release notes and English/Portuguese documentation identify
6.x as stable; the website metadata targets `v6.0.0`. Added the missing MIT
`LICENSE`; fixed the 404 docs link (`/docs/v6/en/…`) and the homepage.
A clean consumer install (path repo)
runs the API smoke test and `vendor/bin/cacheer`, and a consumer-owned store
passes `StoreConformance` via its own `autoload-dev` mapping — now documented in
WRITING_A_STORE and the custom-stores guide. The changelog records the complete
6.0.0 release. Publish the stable tag after the final release checks.

## Decisions that need their own discussion

| Decision | Why it matters | Default direction |
|---|---|---|
| Envelope/metadata authentication | Payload encryption does not automatically authenticate headers, identity, or expiry. | State the actual guarantee; propose a version change only if required, with upgrade instructions. |
| Portable key/scope/TTL/platform limits | Valid native input can exceed a backend's limits. | Validate consistently; do not change all identifiers or require a new platform without a concrete reason. |
| Resilient capabilities and recovery | Separate stores cannot share an atomic operation just by implementing the same interface. | Prefer a precise supported contract over adding distributed coordination. |
| Worker/L1 lifecycle | Long-lived arrays, refresh queues, and local copies need explicit lifecycle rules. | Document current limits first; add reset/eviction only for a demonstrated use case. |

## Optional additions after the selected fixes

These are candidates, not 6.0 release requirements. Pick them only when application
code shows the need, and keep each addition small.

| Addition | Use case |
|---|---|
| Scoped public CAS forwarding | Existing store CAS should be usable without manually bypassing cache scopes. |
| Custom negative-value predicate | An application treats false or empty values as ordinary business results. |
| Builder executor/events/custom-store wiring | Existing constructors require repeated setup that a small builder addition can simplify. |
| Bounded in-memory eviction/reset | A real long-running worker needs a defined memory lifecycle. |
| Framework lifecycle or telemetry bridges | An application needs integration that belongs in a separate optional package. |

Additional backends, new facades, automatic legacy-layout importers, daemon-style
warming, and broad serializer registries wait for an actual request or use case.

## Suggested order

1. **R2-01**, as the first standalone fix.
2. **R2-02**, SQL isolation first; then the Redis-specific case.
3. **R2-03**, one affected locking path at a time.
4. **R2-04–06**, separate storage, expiry, and tag patches.
5. **R2-07–10**, separate integration/decorator patches for features kept in 6.0.
6. **R2-11–12**, gathered alongside each fix and finished before the stable release.

Reorder a confirmed data-integrity/security bug ahead of convenience work. Keep
optional API additions out of the first fixes. No delivery dates are assumed.

## Ready for 6.0 when

- [ ] Confirmed isolation, mutation, storage-read, and expiry failures are resolved
  for every feature advertised as stable.
- [ ] Retained decorators and PSR adapters have tested, documented guarantees.
- [ ] Relevant tests, static analysis, style, and required service jobs pass on
  the release commit; dependency metadata/audit checks are current.
- [ ] Installation, examples, migration/rollback, docs, and release notes agree.
- [ ] The release build has been used in a representative application; worker
  behavior is checked if worker support is claimed, and feedback is addressed.
- [ ] Final artifacts and version constraints are verified before publishing 6.0.

Keep completed items linked to their focused change and verification. This file
remains a plan until those changes have actually shipped.
