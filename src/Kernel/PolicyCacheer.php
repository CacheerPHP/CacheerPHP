<?php

declare(strict_types=1);

namespace Silviooosilva\CacheerPhp\Kernel;

use DateInterval;
use Silviooosilva\CacheerPhp\Cacheer;
use Silviooosilva\CacheerPhp\Config\CachePolicy;
use Silviooosilva\CacheerPhp\Contracts\Clock;
use Throwable;

/**
 * A cache view that applies a CachePolicy over any Cacheer.
 *
 * Reads pass straight through. Writes get the policy's default TTL, negative
 * TTL for empty values, and jitter. remember() additionally implements
 * serve-stale-on-error: within the grace window after logical expiry, a failing
 * callback yields the last good value instead of an exception.
 */
final readonly class PolicyCacheer
{
    public function __construct(
        private Cacheer $cache,
        private CachePolicy $policy,
        private Clock $clock,
    ) {
    }

    public function entry(string|Key $key): CacheEntry
    {
        return $this->cache->entry($key);
    }

    public function get(string|Key $key, mixed $default = null): mixed
    {
        return $this->cache->get($key, $default);
    }

    public function has(string|Key $key): bool
    {
        return $this->cache->has($key);
    }

    public function set(
        string|Key $key,
        mixed $value,
        Ttl|DateInterval|int|string|null $ttl = null,
    ): void {
        $this->cache->set($key, $value, $this->policy->resolveTtl($this->normalize($ttl), $value));
    }

    public function delete(string|Key $key): bool
    {
        return $this->cache->delete($key);
    }

    public function clear(): void
    {
        $this->cache->clear();
    }

    public function remember(
        string|Key $key,
        Ttl|DateInterval|int|string|null $ttl,
        callable $callback,
    ): mixed {
        if (!$this->policy->servesStaleOnError()) {
            $entry = $this->cache->entry($key);
            if ($entry->isHit()) {
                return $entry->value();
            }

            $value = $callback();
            $this->cache->set($key, $value, $this->policy->resolveTtl($this->normalize($ttl), $value));

            return $value;
        }

        return $this->rememberServingStale($key, $ttl, $callback);
    }

    public function flexible(string|Key $key, int $fresh, int $stale, callable $callback): mixed
    {
        return $this->cache->flexible($key, $fresh, $stale, $callback);
    }

    /**
     * @param iterable<string|Key> $keys
     * @return array<string, mixed>
     */
    public function many(iterable $keys, mixed $default = null): array
    {
        return $this->cache->many($keys, $default);
    }

    /**
     * @param iterable<array-key, mixed> $values
     */
    public function setMany(
        iterable $values,
        Ttl|DateInterval|int|string|null $ttl = null,
    ): void {
        foreach ($values as $key => $value) {
            $this->cache->set((string) $key, $value, $this->policy->resolveTtl($this->normalize($ttl), $value));
        }
    }

    /**
     * @param iterable<string|Key> $keys
     */
    public function deleteMany(iterable $keys): bool
    {
        return $this->cache->deleteMany($keys);
    }

    private function rememberServingStale(
        string|Key $key,
        Ttl|DateInterval|int|string|null $ttl,
        callable $callback,
    ): mixed {
        $grace = $this->policy->graceSeconds() ?? 0;
        $entry = $this->cache->entry($key);

        if ($entry->isHit()) {
            $remaining = $entry->remainingTtl($this->clock);
            if ($remaining === null || $remaining > $grace) {
                return $entry->value();
            }

            try {
                $value = $callback();
            } catch (Throwable) {
                return $entry->value();
            }

            $this->cache->set($key, $value, $this->hardTtl($ttl, $value, $grace));

            return $value;
        }

        $value = $callback();
        $this->cache->set($key, $value, $this->hardTtl($ttl, $value, $grace));

        return $value;
    }

    private function hardTtl(Ttl|DateInterval|int|string|null $ttl, mixed $value, int $grace): Ttl
    {
        $base = $this->policy->resolveTtl($this->normalize($ttl), $value);
        $seconds = $base->inSeconds();

        return $seconds === null ? $base : Ttl::seconds($seconds + $grace);
    }

    private function normalize(Ttl|DateInterval|int|string|null $ttl): Ttl|int|string|null
    {
        return $ttl instanceof DateInterval ? Ttl::from($ttl) : $ttl;
    }
}
