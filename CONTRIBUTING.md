# Contributing to CacheerPHP

Thank you for your interest in contributing to CacheerPHP! Please read the guidelines below before opening a pull request.

---

## Requirements

- PHP >= 8.2
- Composer
- Node.js / npm (used to run code style tooling)
- Redis, MySQL, or PostgreSQL only when working on their integration suites

---

## Getting Started

```bash
git clone https://github.com/silviooosilva/cacheer-php.git
cd cacheer-php
composer install
```

---

## Code Style

This project enforces a consistent code style using [PHP-CS-Fixer](https://github.com/PHP-CS-Fixer/PHP-CS-Fixer).

**Running the linter is mandatory before every pull request.**

```bash
npm run lint:fix
```

To check for violations without applying fixes:

```bash
npm run lint:check
```

### Rules enforced

| Rule | Description |
|------|-------------|
| `@PSR12` | Full PSR-12 coding standard |
| `array_syntax` | Short array syntax `[]` |
| `ordered_imports` | `use` statements sorted alphabetically |
| `no_unused_imports` | Remove unused `use` declarations |
| `single_quote` | Single quotes for strings without interpolation |
| `trailing_comma_in_multiline` | Trailing commas in multiline arrays, arguments, and parameters |
| `no_extra_blank_lines` | No extraneous blank lines |
| `blank_line_after_namespace` | Blank line required after `namespace` declaration |
| `visibility_required` | Explicit `public`/`protected`/`private` on properties, methods, and constants |
| `modernize_types_casting` | Use `(int)` instead of `intval()`, etc. |
| `no_superfluous_phpdoc_tags` | Remove `@param`/`@return` tags that duplicate type hints |
| `phpdoc_trim` | No leading/trailing blank lines inside PHPDoc blocks |
| `binary_operator_spaces` | Single space around binary operators |
| `concat_space` | Space around `.` concatenation |
| `phpdoc_line_span` | PHPDoc blocks for properties, methods, and constants must be multiline |

> **Note:** `declare(strict_types=1)` is intentionally **not** enforced by the linter. It is being adopted incrementally. Do not add it to files that don't already have it unless you have verified the file is safe and tests pass.

---

## Running Tests

The default suite is service-free. It covers unit, feature, file, array, and
SQLite-backed behavior:

```bash
composer test
```

Run it in parallel to verify worker isolation:

```bash
composer test:parallel
```

Run the reusable store contract and concurrency suites:

```bash
composer test:contract
composer test:concurrency
```

Service-backed tests live under `tests/Integration`:

```bash
composer test:integration
composer test:integration:redis
composer test:integration:database
```

Unavailable services are reported as skipped during local development. CI sets
`CACHEER_REQUIRE_REDIS=1` or `CACHEER_REQUIRE_DATABASE=1`, which makes a missing
service fail instead. MySQL and PostgreSQL jobs provide their connection details
through `DB_*`; Redis uses `REDIS_*`.

To run every available suite:

```bash
composer test:all
```

Run static analysis and the benchmark payload schema:

```bash
composer analyse
composer test:benchmark
```

Do not document a fixed test count here. The passing count changes whenever
coverage is added; the command's exit status is the source of truth.

---

## Pull Request Checklist

Before opening a PR, make sure you have:

- [ ] Run `npm run lint:fix` and committed the result
- [ ] Run `composer test` and confirmed the service-free suite passes
- [ ] Run `composer test:contract` and `composer analyse`
- [ ] Run `composer test:concurrency` for locking or invalidation changes
- [ ] Run the relevant integration suite for driver changes
- [ ] Added or updated tests for any changed behavior
- [ ] Kept changes focused — one concern per PR
- [ ] Written a clear PR description explaining what changed and why

---

## Commit Style

Use short, imperative commit messages that describe the intent, not the diff:

```
Add TTL support to ArrayCacheStore
Fix incorrect return value in add() when key exists
Refactor FileCacheStore to use envelope format
```

---

## Reporting Issues

Open an issue at [github.com/silviooosilva/cacheer-php/issues](https://github.com/silviooosilva/cacheer-php/issues) and include:

- PHP version
- Cache driver being used
- A minimal reproducible example
- The full error message or unexpected behavior
