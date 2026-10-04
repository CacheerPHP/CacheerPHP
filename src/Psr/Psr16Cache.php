<?php

declare(strict_types=1);

namespace Silviooosilva\CacheerPhp\Psr;

use DateInterval;
use DateTimeImmutable;
use Psr\SimpleCache\CacheInterface;
use Silviooosilva\CacheerPhp\Contracts\Cache;
use Silviooosilva\CacheerPhp\Exceptions\CacheException;
use Silviooosilva\CacheerPhp\Exceptions\CacheInvalidArgumentException;
use Silviooosilva\CacheerPhp\Exceptions\InvalidKeyException;
use Silviooosilva\CacheerPhp\Exceptions\InvalidTtlException;
use Silviooosilva\CacheerPhp\Kernel\Key;

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
     * @param null|int|DateInterval $ttl
     * @return bool
     */
    public function set(string $key, mixed $value, null|int|DateInterval $ttl = null): bool
    {
        $key = $this->validateKey($key);
        $seconds = $this->normalizeTtl($ttl);

        return $this->attempt(function () use ($key, $value, $seconds): void {
            if ($seconds !== null && $seconds <= 0) {
                $this->cache->delete($key);

                return;
            }

            $this->cache->set($key, $value, $seconds);
        });
    }

    /**
     * @param string $key
     * @return bool
     */
    public function delete(string $key): bool
    {
        $key = $this->validateKey($key);

        return $this->attempt(function () use ($key): void {
            $this->cache->delete($key);
        });
    }

    /**
     * @return bool
     */
    public function clear(): bool
    {
        return $this->attempt(function (): void {
            $this->cache->clear();
        });
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
     * @param iterable<mixed> $values
     * @param null|int|DateInterval $ttl
     * @return bool
     */
    public function setMultiple(iterable $values, null|int|DateInterval $ttl = null): bool
    {
        // Validate every key first, so an invalid one rejects the whole call
        // before anything is written.
        $validated = [];
        foreach ($values as $key => $value) {
            $validated[] = [$this->validateKey($key), $value];
        }

        $seconds = $this->normalizeTtl($ttl);
        $ok = true;
        foreach ($validated as [$key, $value]) {
            $ok = $this->set($key, $value, $seconds) && $ok;
        }

        return $ok;
    }

    /**
     * @param iterable<mixed> $keys
     * @return bool
     */
    public function deleteMultiple(iterable $keys): bool
    {
        $keys = $this->validateKeys($keys);

        return $this->attempt(function () use ($keys): void {
            $this->cache->deleteMany($keys);
        });
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
     * Applies the PSR-16 key rules and the native ones, so every invalid key is
     * reported as the PSR InvalidArgumentException. Integer keys are accepted,
     * since PHP turns numeric array keys passed to setMultiple() into integers.
     *
     * @param mixed $key
     * @return string
     */
    private function validateKey(mixed $key): string
    {
        if (is_int($key)) {
            $key = (string) $key;
        }

        if (!is_string($key)) {
            throw CacheInvalidArgumentException::create(sprintf('Cache keys must be strings, %s given.', get_debug_type($key)));
        }

        if ($key === '') {
            throw CacheInvalidArgumentException::create('Cache key must not be empty.');
        }

        if (strpbrk($key, self::RESERVED) !== false) {
            throw CacheInvalidArgumentException::create(
                sprintf('Cache key "%s" contains reserved characters (%s).', $key, self::RESERVED),
            );
        }

        try {
            Key::named($key);
        } catch (InvalidKeyException $exception) {
            throw CacheInvalidArgumentException::create($exception->getMessage());
        }

        return $key;
    }

    /**
     * Runs a write and reports a store failure as false, as PSR-16 requires;
     * an invalid TTL still surfaces as the PSR InvalidArgumentException.
     *
     * @param callable(): void $operation
     * @return bool
     */
    private function attempt(callable $operation): bool
    {
        try {
            $operation();

            return true;
        } catch (InvalidKeyException|InvalidTtlException $exception) {
            throw CacheInvalidArgumentException::create($exception->getMessage());
        } catch (CacheException) {
            return false;
        }
    }

    /**
     * @param iterable<mixed> $keys
     * @return list<string>
     */
    private function validateKeys(iterable $keys): array
    {
        $validated = [];
        foreach ($keys as $key) {
            $validated[] = $this->validateKey($key);
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
