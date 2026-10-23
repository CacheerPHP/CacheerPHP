<?php

declare(strict_types=1);

namespace Silviooosilva\CacheerPhp\Observability;

use Silviooosilva\CacheerPhp\Kernel\Ttl;
use Throwable;

/**
 * An immutable, typed record of a single cache operation.
 *
 * A cached value is never carried here unless value capture is explicitly
 * enabled ($hasValue), so telemetry is safe by default. `bytes` is the
 * serialized size and is safe to record regardless — it exposes the size, not
 * the contents. It is null for arrays and objects unless value capture is on:
 * sizing them would mean serializing every value on every call.
 */
final readonly class CacheEvent
{
    /**
     * @param CacheEventType $type
     * @param string $store
     * @param ?string $key
     * @param float $durationMicros
     * @param ?int $bytes
     * @param ?int $count
     * @param ?Throwable $error
     * @param bool $hasValue
     * @param mixed $value
     * @param ?string $scope The key's scope as a path ('' for the root scope); null when not reported.
     * @param ?Ttl $ttl The lifetime written (Ttl::forever() for no expiry); null when not reported.
     * @param ?string $operation The store operation, such as 'get', 'touch', or 'clearTag'; null when not reported.
     */
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
        public ?string $scope = null,
        public ?Ttl $ttl = null,
        public ?string $operation = null,
    ) {
    }

    /**
     * The same event, described further. Unknown context stays null, so a
     * consumer can tell "not reported" from the root scope or a forever TTL.
     *
     * @param ?string $scope
     * @param ?Ttl $ttl
     * @param ?string $operation
     * @return CacheEvent
     */
    public function withContext(?string $scope = null, ?Ttl $ttl = null, ?string $operation = null): self
    {
        return new self(
            $this->type,
            $this->store,
            $this->key,
            $this->durationMicros,
            $this->bytes,
            $this->count,
            $this->error,
            $this->hasValue,
            $this->value,
            $scope ?? $this->scope,
            $ttl ?? $this->ttl,
            $operation ?? $this->operation,
        );
    }

    /**
     * @param string $store
     * @param string $key
     * @param float $duration
     * @param ?int $bytes
     * @param bool $hasValue
     * @param mixed $value
     * @param ?string $scope
     * @param ?string $operation
     * @return CacheEvent
     */
    public static function hit(string $store, string $key, float $duration, ?int $bytes = null, bool $hasValue = false, mixed $value = null, ?string $scope = null, ?string $operation = null): self
    {
        return new self(CacheEventType::Hit, $store, $key, $duration, $bytes, hasValue: $hasValue, value: $value, scope: $scope, operation: $operation);
    }

    /**
     * @param string $store
     * @param string $key
     * @param float $duration
     * @param ?string $scope
     * @param ?string $operation
     * @return CacheEvent
     */
    public static function miss(string $store, string $key, float $duration, ?string $scope = null, ?string $operation = null): self
    {
        return new self(CacheEventType::Miss, $store, $key, $duration, scope: $scope, operation: $operation);
    }

    /**
     * @param string $store
     * @param string $key
     * @param float $duration
     * @param ?int $bytes
     * @param bool $hasValue
     * @param mixed $value
     * @param int|null $count Optional quantity the write carries — a counter's new
                       value after increment(), or how many tags were added.
     * @param ?string $scope
     * @param ?Ttl $ttl
     * @param ?string $operation
     * @return CacheEvent
     */
    public static function written(string $store, string $key, float $duration, ?int $bytes = null, bool $hasValue = false, mixed $value = null, ?int $count = null, ?string $scope = null, ?Ttl $ttl = null, ?string $operation = null): self
    {
        return new self(CacheEventType::Write, $store, $key, $duration, $bytes, $count, hasValue: $hasValue, value: $value, scope: $scope, ttl: $ttl, operation: $operation);
    }

    /**
     * @param string $store
     * @param string $key
     * @param float $duration
     * @param bool $existed
     * @param ?string $scope
     * @param ?string $operation
     * @return CacheEvent
     */
    public static function deleted(string $store, string $key, float $duration, bool $existed, ?string $scope = null, ?string $operation = null): self
    {
        return new self(CacheEventType::Delete, $store, $key, $duration, count: $existed ? 1 : 0, scope: $scope, operation: $operation);
    }

    /**
     * @param string $store
     * @param float $duration
     * @return CacheEvent
     */
    public static function cleared(string $store, float $duration): self
    {
        return new self(CacheEventType::Clear, $store, null, $duration);
    }

    /**
     * @param string $store
     * @param float $duration
     * @param int $removed
     * @return CacheEvent
     */
    public static function pruned(string $store, float $duration, int $removed): self
    {
        return new self(CacheEventType::Prune, $store, null, $duration, count: $removed);
    }

    /**
     * @param string $store
     * @param ?string $key
     * @param float $duration
     * @param Throwable $error
     * @return CacheEvent
     */
    public static function failed(string $store, ?string $key, float $duration, Throwable $error): self
    {
        return new self(CacheEventType::Failure, $store, $key, $duration, error: $error);
    }

    /**
     * @param string $store
     * @param string $key
     * @return CacheEvent
     */
    public static function promoted(string $store, string $key): self
    {
        return new self(CacheEventType::Promotion, $store, $key);
    }

    /**
     * @param string $store
     * @param string $key
     * @return CacheEvent
     */
    public static function staleServed(string $store, string $key): self
    {
        return new self(CacheEventType::StaleServed, $store, $key);
    }

    /**
     * @param string $store
     * @param string $key
     * @return CacheEvent
     */
    public static function refreshed(string $store, string $key): self
    {
        return new self(CacheEventType::Refresh, $store, $key);
    }

    /**
     * @param string $store
     * @param string $key
     * @return CacheEvent
     */
    public static function lockContended(string $store, string $key): self
    {
        return new self(CacheEventType::LockContended, $store, $key);
    }

    /**
     * @param string $store
     * @param string $key
     * @param float $duration How long the loader ran, in microseconds.
     * @return CacheEvent
     */
    public static function computed(string $store, string $key, float $duration): self
    {
        return new self(CacheEventType::Computed, $store, $key, $duration);
    }

    /**
     * @param string $store
     * @param Throwable $error The failure that opened the circuit.
     * @return CacheEvent
     */
    public static function circuitOpened(string $store, Throwable $error): self
    {
        return new self(CacheEventType::CircuitOpened, $store, error: $error);
    }

    /**
     * @param string $store
     * @return CacheEvent
     */
    public static function circuitClosed(string $store): self
    {
        return new self(CacheEventType::CircuitClosed, $store);
    }

    /**
     * @param string $store
     * @param ?string $key The key, when the operation concerned one.
     * @param int $count How many keys the operation covered.
     * @return CacheEvent
     */
    public static function fallbackServed(string $store, ?string $key, int $count = 1): self
    {
        return new self(CacheEventType::FallbackServed, $store, $key, count: $count);
    }

    /**
     * @param string $store
     * @param float $duration
     * @param ?int $invalidated Keys and bulk invalidations replayed; null when the primary was cleared instead.
     * @return CacheEvent
     */
    public static function reconciled(string $store, float $duration, ?int $invalidated): self
    {
        return new self(CacheEventType::Reconciled, $store, null, $duration, count: $invalidated);
    }
}
