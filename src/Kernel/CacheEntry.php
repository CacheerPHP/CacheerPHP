<?php

declare(strict_types=1);

namespace Silviooosilva\CacheerPhp\Kernel;

use Silviooosilva\CacheerPhp\Contracts\Clock;
use Silviooosilva\CacheerPhp\Exceptions\CacheMissException;

/**
 * The result of a lookup: hit or miss, the value, and its timestamps.
 *
 * Returning an entry rather than a bare value is what lets a stored null be told
 * apart from a miss.
 */
final readonly class CacheEntry
{
    /**
     * @param Key $key
     * @param bool $hit
     * @param mixed $value
     * @param ?int $createdAt
     * @param ?int $expiresAt
     */
    private function __construct(
        private Key $key,
        private bool $hit,
        private mixed $value,
        private ?int $createdAt,
        private ?int $expiresAt,
    ) {
    }

    /**
     * @param Key $key
     * @return CacheEntry
     */
    public static function miss(Key $key): self
    {
        return new self($key, false, null, null, null);
    }

    /**
     * @param Key $key
     * @param mixed $value
     * @param int $createdAt
     * @param ?int $expiresAt
     * @return CacheEntry
     */
    public static function hit(Key $key, mixed $value, int $createdAt, ?int $expiresAt): self
    {
        return new self($key, true, $value, $createdAt, $expiresAt);
    }

    /**
     * @return Key
     */
    public function key(): Key
    {
        return $this->key;
    }

    /**
     * @return bool
     */
    public function isHit(): bool
    {
        return $this->hit;
    }

    /**
     * @return bool
     */
    public function isMiss(): bool
    {
        return !$this->hit;
    }

    /**
     * @return mixed
     */
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

    /**
     * @param mixed $default
     * @return mixed
     */
    public function valueOr(mixed $default = null): mixed
    {
        return $this->hit ? $this->value : $default;
    }

    /**
     * @return ?int
     */
    public function createdAt(): ?int
    {
        return $this->createdAt;
    }

    /**
     * @return ?int
     */
    public function expiresAt(): ?int
    {
        return $this->expiresAt;
    }

    /**
     * @param Clock $clock
     * @return bool
     */
    public function isExpired(Clock $clock): bool
    {
        return $this->hit
            && $this->expiresAt !== null
            && $this->expiresAt <= $clock->now();
    }

    /**
     * @param Clock $clock
     * @return ?int
     */
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
