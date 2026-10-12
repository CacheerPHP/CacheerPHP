# Copilot — CacheerPHP

Read the root [AGENTS.md](../AGENTS.md) before editing or reviewing this repository.
It contains the full architecture, behavior, security, and verification guidance.
Read linked source and tests for the operation you are changing.

- Target `6.x` or a task branch based on it; preserve existing local changes.
- This is the PHP 8.3+ library `silviooosilva/cacheer-php`, using namespace
  `Silviooosilva\CacheerPhp\`. Keep it framework-independent and instance-first.
- Keep `Store` minimal and resolve optional capabilities through `Capabilities`;
  decorator `instanceof` checks alone do not establish backend support.
- Preserve scopes, expiry, falsy cached values, atomicity, storage authentication,
  PSR contracts, and transparent telemetry. The default refresh executor is synchronous.
- Prefer a focused fix with behavioral regression coverage. Do not rewrite the
  architecture, add benchmarks, or introduce unrelated dependencies.
- Use `composer test`, `composer lint`, and `composer analyse` for code changes;
  follow `CONTRIBUTING.md` and run affected contract/integration checks.
  For prose-only changes, verify references and `git diff --check`.
- Do not edit `vendor/`, force-add ignored artifacts, or change sibling repos
  without task scope. Commit/push/publish only when requested.
- Never add AI author notes, assistant signatures, or AI co-author trailers.

For reviews, prioritize concrete correctness/security regressions and missing
behavioral coverage. Explain the trigger; avoid speculative redesign requests.
