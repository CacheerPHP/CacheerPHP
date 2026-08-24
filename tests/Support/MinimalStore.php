<?php

declare(strict_types=1);

namespace Tests\Support;

use Silviooosilva\CacheerPhp\Contracts\Store;
use Silviooosilva\CacheerPhp\Kernel\CacheEntry;
use Silviooosilva\CacheerPhp\Kernel\Key;
use Silviooosilva\CacheerPhp\Kernel\Ttl;

/**
 * A third-party store as small as the contract allows: the four core methods
 * and not one capability. Stands in for stores outside this repository, which
 * are the only ones that can actually lack a capability — every built-in store
 * implements all of them.
 */
final class MinimalStore implements Store
{
    /**
     * @var array<string, array{value: mixed, createdAt: int, expiresAt: int|null}>
     */
    private array $entries = [];

    public function __construct(private readonly FakeClock $clock)
    {
    }

    public function get(Key $key): CacheEntry
    {
        $id = $key->identity();
        $record = $this->entries[$id] ?? null;

        if ($record === null) {
            return CacheEntry::miss($key);
        }

        if ($record['expiresAt'] !== null && $record['expiresAt'] <= $this->clock->now()) {
            unset($this->entries[$id]);

            return CacheEntry::miss($key);
        }

        return CacheEntry::hit($key, $record['value'], $record['createdAt'], $record['expiresAt']);
    }

    public function set(Key $key, mixed $value, Ttl $ttl): void
    {
        $this->entries[$key->identity()] = [
            'value'     => $value,
            'createdAt' => $this->clock->now(),
            'expiresAt' => $ttl->expiresAt($this->clock),
        ];
    }

    public function delete(Key $key): bool
    {
        $id = $key->identity();
        $existed = isset($this->entries[$id]);
        unset($this->entries[$id]);

        return $existed;
    }

    public function clear(): void
    {
        $this->entries = [];
    }
}
