# Changelog

All notable changes to CacheerPHP will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [5.0.0] - 2026-03-09

### Breaking Changes
- PHP 8.2+ now required (was 8.0+)
- `Cacheer::$cacheStore` and `Cacheer::$options` are now **private** — use `getCacheStore()`/`setCacheStore()`, `getOptions()`/`setOption()`/`setOptions()`
- `add()` now returns `true` when key is stored, `false` when key already exists (was inverted in v4)
- `CacheDataFormatter::toJson()` now returns `string` and throws `\JsonException` on failure
- Encryption uses random IV (prepended to ciphertext, base64-encoded) — existing encrypted values are unreadable after upgrade; flush encrypted caches before upgrading
- `FileCacheStore` envelope format changed to `{data, expires_at, ttl}` — flush existing file caches after upgrade

### Added
- PSR-16 SimpleCache adapter (`Cacheer\Psr\Psr16CacheAdapter`)
- PSR-3 logging compliance (`CacheLogger` extends `\Psr\Log\AbstractLogger`)
- `Cacheer::stats()` — returns driver class name, compression flag, and encryption flag
- `Cacheer::resetInstance()` — clears the shared static singleton (useful in tests)
- `Cacheer::setInstance()` — injects a custom singleton for testing
- `Cacheer::getOption($key, $default)` — reads a single option with fallback
- `CacheInvalidArgumentException` — PSR-16 compliant exception
- `DateInterval` and `null` TTL support in `putCache()`, `remember()`, `renewCache()`
- New examples: PSR-16 adapter, DateInterval TTL, falsy values, conditional add, stats/instance management

### Changed
- `CacheTimeConstants::CACHE_FOREVER_TTL` = `PHP_INT_MAX` (fixes 32-bit overflow)
- Redis: `PHP_INT_MAX` TTL now uses `SET` without expiry instead of `SETEX`
- Database: `PHP_INT_MAX` TTL stored as `'9999-12-31 23:59:59'`
- `remember()`, `increment()`, `getAndForget()` use `isSuccess()` instead of `!empty()` for falsy value support
- `FileCacheStore` now stores per-item TTL in the cache envelope

### Fixed
- Falsy values (`0`, `''`, `false`, `null`, `[]`) can now be cached and retrieved correctly
- `CACHE_FOREVER_TTL` no longer overflows on 32-bit systems
- `CacheLogger::rotateLog()` now writes rotated files to the correct directory
- Encryption IV is now random per write (was using a static IV derived from the key)

## [4.7.7] - 2025-12-XX

- Previous stable release. See [GitHub releases](https://github.com/silviooosilva/CacheerPHP/releases) for details.
