<?php

declare(strict_types=1);

namespace Silviooosilva\CacheerPhp\Kernel;

use DateInterval;
use DateTimeInterface;
use Silviooosilva\CacheerPhp\Contracts\Clock;
use Silviooosilva\CacheerPhp\Exceptions\InvalidTtlException;

/**
 * A lifetime, expressed however the caller finds clearest.
 *
 * Accepts seconds, a human string ('10 minutes'), a DateInterval, or null for
 * forever. Overflow is guarded rather than silently wrapping, and forever is
 * "no expiry" rather than a large integer.
 */
final readonly class Ttl implements \Stringable
{
    /**
     * @param ?int $seconds
     */
    private function __construct(private ?int $seconds)
    {
    }

    /**
     * @return Ttl
     */
    public static function forever(): self
    {
        return new self(null);
    }

    /**
     * @param int $seconds
     * @return Ttl
     */
    public static function seconds(int $seconds): self
    {
        if ($seconds <= 0) {
            throw new InvalidTtlException('TTL seconds must be greater than zero.');
        }

        return new self($seconds);
    }

    /**
     * @param int $minutes
     * @return Ttl
     */
    public static function minutes(int $minutes): self
    {
        return self::seconds(self::multiply($minutes, 60));
    }

    /**
     * @param int $hours
     * @return Ttl
     */
    public static function hours(int $hours): self
    {
        return self::seconds(self::multiply($hours, 3600));
    }

    /**
     * @param int $days
     * @return Ttl
     */
    public static function days(int $days): self
    {
        return self::seconds(self::multiply($days, 86400));
    }

    /**
     * @param int $weeks
     * @return Ttl
     */
    public static function weeks(int $weeks): self
    {
        return self::seconds(self::multiply($weeks, 604800));
    }

    /**
     * @param DateTimeInterface $expiration
     * @param Clock $clock
     * @return Ttl
     */
    public static function until(DateTimeInterface $expiration, Clock $clock): self
    {
        return self::seconds($expiration->getTimestamp() - $clock->now());
    }

    /**
     * @param Ttl|DateInterval|string|int|null $ttl
     * @return Ttl
     */
    public static function from(self|DateInterval|int|string|null $ttl): self
    {
        if ($ttl instanceof self) {
            return $ttl;
        }

        if ($ttl === null) {
            return self::forever();
        }

        if (is_int($ttl)) {
            return self::seconds($ttl);
        }

        if ($ttl instanceof DateInterval) {
            return self::fromDateInterval($ttl);
        }

        $normalized = strtolower(trim($ttl));
        if ($normalized === 'forever') {
            return self::forever();
        }

        if (ctype_digit($normalized)) {
            return self::seconds((int) $normalized);
        }

        if (preg_match('/^(\d+)\s*(second|minute|hour|day|week)s?$/', $normalized, $matches) !== 1) {
            throw new InvalidTtlException(sprintf('Unsupported TTL value "%s".', $ttl));
        }

        $amount = (int) $matches[1];

        return match ($matches[2]) {
            'second' => self::seconds($amount),
            'minute' => self::minutes($amount),
            'hour'   => self::hours($amount),
            'day'    => self::days($amount),
            'week'   => self::weeks($amount),
        };
    }

    /**
     * @return bool
     */
    public function isForever(): bool
    {
        return $this->seconds === null;
    }

    /**
     * @return ?int
     */
    public function inSeconds(): ?int
    {
        return $this->seconds;
    }

    /**
     * @param Clock $clock
     * @return ?int
     */
    public function expiresAt(Clock $clock): ?int
    {
        if ($this->seconds === null) {
            return null;
        }

        $now = $clock->now();
        if ($this->seconds > PHP_INT_MAX - $now) {
            throw new InvalidTtlException('TTL exceeds the largest expiration supported by this platform.');
        }

        return $now + $this->seconds;
    }

    /**
     * @return string
     */
    public function __toString(): string
    {
        return $this->seconds === null ? 'forever' : $this->seconds . ' seconds';
    }

    /**
     * @param DateInterval $interval
     * @return Ttl
     */
    private static function fromDateInterval(DateInterval $interval): self
    {
        if ($interval->invert === 1) {
            throw new InvalidTtlException('A negative DateInterval cannot be used as a TTL.');
        }

        if ($interval->y !== 0 || $interval->m !== 0) {
            throw new InvalidTtlException('TTL DateIntervals cannot contain years or months.');
        }

        $days = $interval->days === false ? $interval->d : $interval->days;
        $seconds = self::multiply($days, 86400);
        $seconds = self::add($seconds, self::multiply($interval->h, 3600));
        $seconds = self::add($seconds, self::multiply($interval->i, 60));
        $seconds = self::add($seconds, $interval->s);

        return self::seconds($seconds);
    }

    /**
     * @param int $value
     * @param int $multiplier
     * @return int
     */
    private static function multiply(int $value, int $multiplier): int
    {
        if ($value < 0) {
            throw new InvalidTtlException('TTL values cannot be negative.');
        }

        if ($value > intdiv(PHP_INT_MAX, $multiplier)) {
            throw new InvalidTtlException('TTL exceeds the largest duration supported by this platform.');
        }

        return $value * $multiplier;
    }

    /**
     * @param int $left
     * @param int $right
     * @return int
     */
    private static function add(int $left, int $right): int
    {
        if ($right > PHP_INT_MAX - $left) {
            throw new InvalidTtlException('TTL exceeds the largest duration supported by this platform.');
        }

        return $left + $right;
    }
}
