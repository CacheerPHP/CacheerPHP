<?php

declare(strict_types=1);

namespace Silviooosilva\CacheerPhp\Kernel;

use Silviooosilva\CacheerPhp\Contracts\Clock;
use Silviooosilva\CacheerPhp\Exceptions\CacheMissException;

final readonly class CacheEntry
{
    private function __construct(
        private Key $key,
        private bool $hit,
        private mixed $value,
        private ?int $createdAt,
        private ?int $expiresAt,
    ) {
    }

    public static function miss(Key $key): self
    {
        return new self($key, false, null, null, null);
    }

    public static function hit(Key $key, mixed $value, int $createdAt, ?int $expiresAt): self
    {
        return new self($key, true, $value, $createdAt, $expiresAt);
    }

    public function key(): Key
    {
        return $this->key;
    }

    public function isHit(): bool
    {
        return $this->hit;
    }

    public function isMiss(): bool
    {
        return !$this->hit;
    }

    public function value(): mixed
    {
        if (!$this->hit) {
            throw new CacheMissException(sprintf(
                'Cache key "%s" is a miss and has no value.',
                $this->key,
            ));
        }

        return $this->value;
    }

    public function valueOr(mixed $default = null): mixed
    {
        return $this->hit ? $this->value : $default;
    }

    public function createdAt(): ?int
    {
        return $this->createdAt;
    }

    public function expiresAt(): ?int
    {
        return $this->expiresAt;
    }

    public function isExpired(Clock $clock): bool
    {
        return $this->hit
            && $this->expiresAt !== null
            && $this->expiresAt <= $clock->now();
    }

    public function remainingTtl(Clock $clock): ?int
    {
        if (!$this->hit) {
            return 0;
        }

        if ($this->expiresAt === null) {
            return null;
        }

        return max(0, $this->expiresAt - $clock->now());
    }
}
