<?php

declare(strict_types=1);

namespace Silviooosilva\CacheerPhp\Psr;

use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use Psr\Cache\CacheItemInterface;
use Silviooosilva\CacheerPhp\Contracts\Clock;
use Silviooosilva\CacheerPhp\Kernel\Ttl;

/**
 * A PSR-6 cache item. Only {@see Psr6Pool} constructs these.
 *
 * Expiration is kept as the caller expressed it — none, a relative duration
 * (expiresAfter), or an absolute time (expiresAt) — and only resolved to a TTL
 * against the pool's clock at save time, so a relative expiry never leaks the
 * wall clock into a fake-clock test.
 */
final class Psr6Item implements CacheItemInterface
{
    private const NONE = 0;

    private const RELATIVE = 1;

    private const ABSOLUTE = 2;

    /**
     * @var mixed
     */
    private mixed $value = null;

    /**
     * @var int
     */
    private int $mode = self::NONE;

    /**
     * @var int
     */
    private int $amount = 0;

    /**
     * @param string $key
     * @param bool $hit
     */
    private function __construct(
        private readonly string $key,
        private readonly bool $hit,
    ) {
    }

    /**
     * @param string $key
     * @return Psr6Item
     */
    public static function miss(string $key): self
    {
        return new self($key, false);
    }

    /**
     * @param string $key
     * @param mixed $value
     * @param ?int $expiresAt
     * @return Psr6Item
     */
    public static function hit(string $key, mixed $value, ?int $expiresAt): self
    {
        $item = new self($key, true);
        $item->value = $value;

        if ($expiresAt !== null) {
            $item->mode = self::ABSOLUTE;
            $item->amount = $expiresAt;
        }

        return $item;
    }

    /**
     * @return string
     */
    public function getKey(): string
    {
        return $this->key;
    }

    /**
     * @return mixed
     */
    public function get(): mixed
    {
        return $this->hit ? $this->value : null;
    }

    /**
     * @return bool
     */
    public function isHit(): bool
    {
        return $this->hit;
    }

    /**
     * @param mixed $value
     * @return static
     */
    public function set(mixed $value): static
    {
        $this->value = $value;

        return $this;
    }

    /**
     * @param ?DateTimeInterface $expiration
     * @return static
     */
    public function expiresAt(?DateTimeInterface $expiration): static
    {
        if ($expiration === null) {
            $this->mode = self::NONE;

            return $this;
        }

        $this->mode = self::ABSOLUTE;
        $this->amount = $expiration->getTimestamp();

        return $this;
    }

    /**
     * @param DateInterval|int|null $time
     * @return static
     */
    public function expiresAfter(int|DateInterval|null $time): static
    {
        if ($time === null) {
            $this->mode = self::NONE;

            return $this;
        }

        $this->mode = self::RELATIVE;
        $this->amount = $time instanceof DateInterval ? $this->intervalToSeconds($time) : $time;

        return $this;
    }

    /**
     * @internal Used by the pool to persist the item.
     * @return mixed
     */
    public function rawValue(): mixed
    {
        return $this->value;
    }

    /**
     * @param Clock $clock
     * @internal Resolve to a kernel TTL: null = forever, false = already
          expired (delete), or a Ttl to store.
     * @return Ttl|false|null
     */
    public function resolveTtl(Clock $clock): Ttl|false|null
    {
        return match ($this->mode) {
            self::RELATIVE => $this->amount <= 0 ? false : Ttl::seconds($this->amount),
            self::ABSOLUTE => $this->fromAbsolute($clock),
            default        => null,
        };
    }

    /**
     * @param Clock $clock
     * @return Ttl|false
     */
    private function fromAbsolute(Clock $clock): Ttl|false
    {
        $seconds = $this->amount - $clock->now();

        return $seconds <= 0 ? false : Ttl::seconds($seconds);
    }

    /**
     * @param DateInterval $interval
     * @return int
     */
    private function intervalToSeconds(DateInterval $interval): int
    {
        $now = new DateTimeImmutable();

        return $now->add($interval)->getTimestamp() - $now->getTimestamp();
    }
}
