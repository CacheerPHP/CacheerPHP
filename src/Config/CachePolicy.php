<?php

declare(strict_types=1);

namespace Silviooosilva\CacheerPhp\Config;

use Closure;
use InvalidArgumentException;
use Silviooosilva\CacheerPhp\Kernel\Ttl;

/**
 * Immutable description of caching behavior layered over a store: a default TTL,
 * TTL jitter, negative caching, and serve-stale-on-error.
 *
 * Bind one with {@see \Silviooosilva\CacheerPhp\Cacheer::withPolicy()}. Every
 * with*() method returns a new instance. The jitter source is injectable so tests are
 * deterministic.
 */
final readonly class CachePolicy
{
    /**
     * @param ?Ttl $defaultTtl
     * @param float $jitterFraction
     * @param ?Ttl $negativeTtl
     * @param ?Ttl $staleGrace
     * @param Closure(): float $randomizer Returns a float in [0, 1).
     */
    private function __construct(
        private ?Ttl $defaultTtl,
        private float $jitterFraction,
        private ?Ttl $negativeTtl,
        private ?Ttl $staleGrace,
        private Closure $randomizer,
    ) {
    }

    /**
     * @return CachePolicy
     */
    public static function defaults(): self
    {
        return new self(null, 0.0, null, null, static fn (): float => mt_rand() / (mt_getrandmax() + 1));
    }

    /**
     * @param Ttl|string|int $ttl
     * @return CachePolicy
     */
    public function withTtl(Ttl|int|string $ttl): self
    {
        return new self(Ttl::from($ttl), $this->jitterFraction, $this->negativeTtl, $this->staleGrace, $this->randomizer);
    }

    /**
     * @param float                 $fraction   Spread as a fraction of the TTL, 0..1 (0.1 = +/-10%).
     * @param (Closure(): float)|null $randomizer Optional deterministic source in [0, 1).
     * @return CachePolicy
     */
    public function withJitter(float $fraction, ?Closure $randomizer = null): self
    {
        if ($fraction < 0.0 || $fraction > 1.0) {
            throw new InvalidArgumentException('Jitter fraction must be between 0 and 1.');
        }

        return new self($this->defaultTtl, $fraction, $this->negativeTtl, $this->staleGrace, $randomizer ?? $this->randomizer);
    }

    /**
     * @param Ttl|string|int $ttl
     * @return CachePolicy
     */
    public function withNegativeTtl(Ttl|int|string $ttl): self
    {
        return new self($this->defaultTtl, $this->jitterFraction, Ttl::from($ttl), $this->staleGrace, $this->randomizer);
    }

    /**
     * @param Ttl|string|int $grace
     * @return CachePolicy
     */
    public function withServeStaleOnError(Ttl|int|string $grace): self
    {
        return new self($this->defaultTtl, $this->jitterFraction, $this->negativeTtl, Ttl::from($grace), $this->randomizer);
    }

    /**
     * Resolve the effective write TTL: fall back to the default, downgrade empty
     * values to the negative TTL, then apply jitter.
     *
     * @param Ttl|string|int|null $requested
     * @param mixed $value
     * @return Ttl
     */
    public function resolveTtl(Ttl|int|string|null $requested, mixed $value): Ttl
    {
        $ttl = $requested === null ? ($this->defaultTtl ?? Ttl::forever()) : Ttl::from($requested);

        if ($this->negativeTtl !== null && $this->isEmpty($value)) {
            $ttl = $this->negativeTtl;
        }

        return $this->jitter($ttl);
    }

    /**
     * @return ?int
     */
    public function graceSeconds(): ?int
    {
        return $this->staleGrace?->inSeconds();
    }

    /**
     * @return bool
     */
    public function servesStaleOnError(): bool
    {
        return $this->staleGrace !== null;
    }

    /**
     * @param Ttl $ttl
     * @return Ttl
     */
    private function jitter(Ttl $ttl): Ttl
    {
        $seconds = $ttl->inSeconds();
        if ($this->jitterFraction === 0.0 || $seconds === null) {
            return $ttl;
        }

        $random = ($this->randomizer)();
        $factor = 1.0 - $this->jitterFraction + (2.0 * $this->jitterFraction * $random);

        return Ttl::seconds(max(1, (int) round($seconds * $factor)));
    }

    /**
     * @param mixed $value
     * @return bool
     */
    private function isEmpty(mixed $value): bool
    {
        return $value === null || $value === [] || $value === '' || $value === false;
    }
}
