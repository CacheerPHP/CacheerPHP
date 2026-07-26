<?php

declare(strict_types=1);

namespace Silviooosilva\CacheerPhp\Observability;

use Throwable;

/**
 * An immutable, typed record of a single cache operation.
 *
 * A cached value is never carried here unless value capture is explicitly
 * enabled ($hasValue), so telemetry is safe by default. `bytes` is the
 * serialized size and is safe to record regardless — it exposes the size, not
 * the contents.
 */
final readonly class CacheEvent
{
    private function __construct(
        public CacheEventType $type,
        public string $store,
        public ?string $key = null,
        public float $durationMicros = 0.0,
        public ?int $bytes = null,
        public ?int $count = null,
        public ?Throwable $error = null,
        public bool $hasValue = false,
        public mixed $value = null,
    ) {
    }

    public static function hit(string $store, string $key, float $duration, ?int $bytes = null, bool $hasValue = false, mixed $value = null): self
    {
        return new self(CacheEventType::Hit, $store, $key, $duration, $bytes, hasValue: $hasValue, value: $value);
    }

    public static function miss(string $store, string $key, float $duration): self
    {
        return new self(CacheEventType::Miss, $store, $key, $duration);
    }

    public static function written(string $store, string $key, float $duration, ?int $bytes = null, bool $hasValue = false, mixed $value = null): self
    {
        return new self(CacheEventType::Write, $store, $key, $duration, $bytes, hasValue: $hasValue, value: $value);
    }

    public static function deleted(string $store, string $key, float $duration, bool $existed): self
    {
        return new self(CacheEventType::Delete, $store, $key, $duration, count: $existed ? 1 : 0);
    }

    public static function cleared(string $store, float $duration): self
    {
        return new self(CacheEventType::Clear, $store, null, $duration);
    }

    public static function pruned(string $store, float $duration, int $removed): self
    {
        return new self(CacheEventType::Prune, $store, null, $duration, count: $removed);
    }

    public static function failed(string $store, ?string $key, float $duration, Throwable $error): self
    {
        return new self(CacheEventType::Failure, $store, $key, $duration, error: $error);
    }

    public static function promoted(string $store, string $key): self
    {
        return new self(CacheEventType::Promotion, $store, $key);
    }

    public static function staleServed(string $store, string $key): self
    {
        return new self(CacheEventType::StaleServed, $store, $key);
    }

    public static function refreshed(string $store, string $key): self
    {
        return new self(CacheEventType::Refresh, $store, $key);
    }

    public static function lockContended(string $store, string $key): self
    {
        return new self(CacheEventType::LockContended, $store, $key);
    }
}
