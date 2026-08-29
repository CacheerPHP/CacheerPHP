<?php

declare(strict_types=1);

namespace Silviooosilva\CacheerPhp\Psr;

use DateInterval;
use DateTimeImmutable;
use Psr\SimpleCache\CacheInterface;
use Silviooosilva\CacheerPhp\Contracts\Cache;
use Silviooosilva\CacheerPhp\Exceptions\CacheInvalidArgumentException;

/**
 * PSR-16 (SimpleCache) adapter over the v6 kernel.
 *
 * Enforces PSR-16 key rules (rejecting the reserved characters {}()/\@:) and
 * TTL semantics (null means "keep forever" here; a non-positive TTL deletes the
 * item). A cached null is a genuine hit, distinct from the caller's default.
 */
final class Psr16Cache implements CacheInterface
{
    private const RESERVED = '{}()/\\@:';

    /**
     * @param Cache $cache
     */
    public function __construct(private readonly Cache $cache)
    {
    }

    /**
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->cache->get($this->validateKey($key), $default);
    }

    /**
     * @param string $key
     * @param mixed $value
     * @param DateInterval|int|null $ttl
     * @return bool
     */
    public function set(string $key, mixed $value, null|int|DateInterval $ttl = null): bool
    {
        $key = $this->validateKey($key);
        $seconds = $this->normalizeTtl($ttl);

        if ($seconds !== null && $seconds <= 0) {
            $this->cache->delete($key);

            return true;
        }

        $this->cache->set($key, $value, $seconds);

        return true;
    }

    /**
     * @param string $key
     * @return bool
     */
    public function delete(string $key): bool
    {
        $this->cache->delete($this->validateKey($key));

        return true;
    }

    /**
     * @return bool
     */
    public function clear(): bool
    {
        $this->cache->clear();

        return true;
    }

    /**
     * @param iterable<string> $keys
     * @param mixed $default
     * @return iterable<string, mixed>
     */
    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        return $this->cache->many($this->validateKeys($keys), $default);
    }

    /**
     * @param iterable<string, mixed> $values
     * @param DateInterval|int|null $ttl
     * @return bool
     */
    public function setMultiple(iterable $values, null|int|DateInterval $ttl = null): bool
    {
        $seconds = $this->normalizeTtl($ttl);

        foreach ($values as $key => $value) {
            $this->set((string) $key, $value, $seconds);
        }

        return true;
    }

    /**
     * @param iterable<string> $keys
     * @return bool
     */
    public function deleteMultiple(iterable $keys): bool
    {
        $this->cache->deleteMany($this->validateKeys($keys));

        return true;
    }

    /**
     * @param string $key
     * @return bool
     */
    public function has(string $key): bool
    {
        return $this->cache->has($this->validateKey($key));
    }

    /**
     * @param string $key
     * @return string
     */
    private function validateKey(string $key): string
    {
        if ($key === '') {
            throw CacheInvalidArgumentException::create('Cache key must not be empty.');
        }

        if (strpbrk($key, self::RESERVED) !== false) {
            throw CacheInvalidArgumentException::create(
                sprintf('Cache key "%s" contains reserved characters (%s).', $key, self::RESERVED),
            );
        }

        return $key;
    }

    /**
     * @param iterable<string> $keys
     * @return list<string>
     */
    private function validateKeys(iterable $keys): array
    {
        $validated = [];
        foreach ($keys as $key) {
            $validated[] = $this->validateKey((string) $key);
        }

        return $validated;
    }

    /**
     * @param DateInterval|int|null $ttl
     * @return ?int
     */
    private function normalizeTtl(null|int|DateInterval $ttl): ?int
    {
        if ($ttl === null) {
            return null;
        }

        if ($ttl instanceof DateInterval) {
            $now = new DateTimeImmutable();

            return $now->add($ttl)->getTimestamp() - $now->getTimestamp();
        }

        return $ttl;
    }
}
