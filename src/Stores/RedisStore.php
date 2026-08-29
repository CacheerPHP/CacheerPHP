<?php

declare(strict_types=1);

namespace Silviooosilva\CacheerPhp\Stores;

use Silviooosilva\CacheerPhp\Config\PipelineConfig;
use Silviooosilva\CacheerPhp\Contracts\AtomicStore;
use Silviooosilva\CacheerPhp\Contracts\BatchStore;
use Silviooosilva\CacheerPhp\Contracts\Clock;
use Silviooosilva\CacheerPhp\Contracts\FlushableScopeStore;
use Silviooosilva\CacheerPhp\Contracts\InspectableStore;
use Silviooosilva\CacheerPhp\Contracts\KeyEncoder;
use Silviooosilva\CacheerPhp\Contracts\Lock;
use Silviooosilva\CacheerPhp\Contracts\LockingStore;
use Silviooosilva\CacheerPhp\Contracts\PrunableStore;
use Silviooosilva\CacheerPhp\Contracts\RedisConnection;
use Silviooosilva\CacheerPhp\Contracts\Store;
use Silviooosilva\CacheerPhp\Contracts\TaggableStore;
use Silviooosilva\CacheerPhp\Contracts\TouchStore;
use Silviooosilva\CacheerPhp\Exceptions\StoreOperationFailedException;
use Silviooosilva\CacheerPhp\Kernel\CacheEntry;
use Silviooosilva\CacheerPhp\Kernel\Key;
use Silviooosilva\CacheerPhp\Kernel\Scope;
use Silviooosilva\CacheerPhp\Kernel\Ttl;
use Silviooosilva\CacheerPhp\Storage\EnvelopeCodec;
use Silviooosilva\CacheerPhp\Storage\KeyEncoder\HashingKeyEncoder;
use Silviooosilva\CacheerPhp\Stores\Support\RedisLock;
use Silviooosilva\CacheerPhp\Stores\Support\StoredRecord;
use Silviooosilva\CacheerPhp\Support\SystemClock;
use UnexpectedValueException;

/**
 * Redis-backed store driven by an injected connection adapter.
 *
 * Expiry is authoritative in the stored record (checked against the injected
 * clock) with a Redis TTL as an eviction backstop, so behavior is deterministic
 * under a fake clock and memory is still reclaimed in production. Every
 * keyspace traversal uses SCAN confined to this store's prefix — never the
 * blocking KEYS — and counters use a lock-guarded read-modify-write because the
 * stored values are opaque encoded envelopes rather than plain integers.
 */
final class RedisStore implements
    Store,
    BatchStore,
    TouchStore,
    PrunableStore,
    InspectableStore,
    FlushableScopeStore,
    TaggableStore,
    AtomicStore,
    LockingStore
{
    /**
     * @var EnvelopeCodec
     */
    private readonly EnvelopeCodec $codec;

    /**
     * @var KeyEncoder
     */
    private readonly KeyEncoder $keyEncoder;

    /**
     * @var Clock
     */
    private readonly Clock $clock;

    /**
     * @param RedisConnection $redis
     * @param string $prefix
     * @param ?EnvelopeCodec $codec
     * @param ?KeyEncoder $keyEncoder
     * @param ?Clock $clock
     */
    public function __construct(
        private readonly RedisConnection $redis,
        private readonly string $prefix = 'cacheer',
        ?EnvelopeCodec $codec = null,
        ?KeyEncoder $keyEncoder = null,
        ?Clock $clock = null,
    ) {
        $this->codec = $codec ?? PipelineConfig::default()->codec();
        $this->keyEncoder = $keyEncoder ?? new HashingKeyEncoder();
        $this->clock = $clock ?? new SystemClock();
    }

    /**
     * @param Key $key
     * @return CacheEntry
     */
    public function get(Key $key): CacheEntry
    {
        $record = $this->readEntry($this->entryKey($key));

        if ($record === null) {
            return CacheEntry::miss($key);
        }

        if ($this->isExpired($record->expiresAt)) {
            $this->redis->delete([$this->entryKey($key)]);

            return CacheEntry::miss($key);
        }

        return CacheEntry::hit($key, $this->codec->decode($record->blob), $record->createdAt, $record->expiresAt);
    }

    /**
     * @param Key $key
     * @param mixed $value
     * @param Ttl $ttl
     */
    public function set(Key $key, mixed $value, Ttl $ttl): void
    {
        $this->write($key, $value, $ttl->expiresAt($this->clock), $ttl->inSeconds());
    }

    /**
     * @param Key $key
     * @return bool
     */
    public function delete(Key $key): bool
    {
        return $this->redis->delete([$this->entryKey($key)]) > 0;
    }

    public function clear(): void
    {
        $this->deleteByPattern($this->prefix . ':e:*');
        $this->deleteByPattern($this->prefix . ':t:*');
    }

    /**
     * @param iterable<Key> $keys
     * @return list<CacheEntry>
     */
    public function getMany(iterable $keys): array
    {
        $keyList = $this->keyList($keys);
        if ($keyList === []) {
            return [];
        }

        $raw = $this->redis->getMany(array_map(fn (Key $key): string => $this->entryKey($key), $keyList));

        $entries = [];
        foreach ($keyList as $index => $key) {
            $entries[] = $this->hydrate($key, $raw[$index] ?? null);
        }

        return $entries;
    }

    /**
     * @param iterable $entries
     * @param Ttl $ttl
     */
    public function setMany(iterable $entries, Ttl $ttl): void
    {
        $expiresAt = $ttl->expiresAt($this->clock);
        $seconds = $ttl->inSeconds();

        foreach ($entries as $entry) {
            $this->write($entry['key'], $entry['value'], $expiresAt, $seconds);
        }
    }

    /**
     * @param iterable<Key> $keys
     * @return bool
     */
    public function deleteMany(iterable $keys): bool
    {
        $deleted = true;

        foreach ($keys as $key) {
            $deleted = $this->delete($key) && $deleted;
        }

        return $deleted;
    }

    /**
     * @param Key $key
     * @param Ttl $ttl
     * @return bool
     */
    public function touch(Key $key, Ttl $ttl): bool
    {
        $record = $this->readEntry($this->entryKey($key));

        if ($record === null || $this->isExpired($record->expiresAt)) {
            return false;
        }

        $this->persist(
            StoredRecord::forKey($key, $record->createdAt, $ttl->expiresAt($this->clock), $record->blob),
            $ttl->inSeconds(),
        );

        return true;
    }

    /**
     * @return int
     */
    public function prune(): int
    {
        $removed = 0;

        foreach ($this->redis->scan($this->prefix . ':e:*') as $entryKey) {
            $record = $this->readEntry($entryKey);
            if ($record !== null && $this->isExpired($record->expiresAt)) {
                $removed += $this->redis->delete([$entryKey]);
            }
        }

        return $removed;
    }

    /**
     * @param ?Scope $scope
     * @return iterable<CacheEntry>
     */
    public function entries(?Scope $scope = null): iterable
    {
        $scope ??= Scope::root();

        foreach ($this->redis->scan($this->prefix . ':e:*') as $entryKey) {
            $record = $this->readEntry($entryKey);
            if ($record === null || $this->isExpired($record->expiresAt)) {
                continue;
            }

            $key = $record->key();
            if ($scope->contains($key->scope())) {
                yield CacheEntry::hit($key, $this->codec->decode($record->blob), $record->createdAt, $record->expiresAt);
            }
        }
    }

    /**
     * @param Scope $scope
     */
    public function clearScope(Scope $scope): void
    {
        if ($scope->isRoot()) {
            $this->clear();

            return;
        }

        $doomed = [];
        foreach ($this->redis->scan($this->prefix . ':e:*') as $entryKey) {
            $record = $this->readEntry($entryKey);
            if ($record !== null && $scope->contains($record->key()->scope())) {
                $doomed[] = $entryKey;
            }
        }

        if ($doomed !== []) {
            $this->redis->delete($doomed);
        }
    }

    /**
     * @param Key $key
     * @param string ...$tags
     */
    public function tag(Key $key, string ...$tags): void
    {
        foreach ($tags as $tag) {
            $this->redis->sAdd($this->tagKey($tag), $this->entryKey($key));
        }
    }

    /**
     * @param string $tag
     * @return int
     */
    public function clearTag(string $tag): int
    {
        $members = $this->redis->sMembers($this->tagKey($tag));
        $removed = $members === [] ? 0 : $this->redis->delete($members);
        $this->redis->delete([$this->tagKey($tag)]);

        return $removed;
    }

    /**
     * @param Key $key
     * @param int $amount
     * @param ?int $initial
     * @param ?Ttl $ttl
     * @return int
     */
    public function increment(Key $key, int $amount = 1, ?int $initial = null, ?Ttl $ttl = null): int
    {
        return $this->guarded($key, function () use ($key, $amount, $initial, $ttl): int {
            $entry = $this->get($key);

            if ($entry->isHit()) {
                $current = $entry->value();
                if (!is_int($current)) {
                    throw new StoreOperationFailedException(
                        'increment',
                        $key,
                        new UnexpectedValueException('Cannot increment a non-integer cache value.'),
                    );
                }
                $next = $current + $amount;
                $expiresAt = $ttl?->expiresAt($this->clock) ?? $entry->expiresAt();
            } else {
                $next = ($initial ?? 0) + $amount;
                $expiresAt = $ttl?->expiresAt($this->clock);
            }

            $this->write($key, $next, $expiresAt, $this->remainingSeconds($expiresAt));

            return $next;
        });
    }

    /**
     * @param Key $key
     * @param mixed $expected
     * @param mixed $value
     * @param ?Ttl $ttl
     * @return bool
     */
    public function compareAndSwap(Key $key, mixed $expected, mixed $value, ?Ttl $ttl = null): bool
    {
        return $this->guarded($key, function () use ($key, $expected, $value, $ttl): bool {
            $entry = $this->get($key);

            if ($entry->isMiss() || $entry->value() !== $expected) {
                return false;
            }

            $expiresAt = $ttl?->expiresAt($this->clock) ?? $entry->expiresAt();
            $this->write($key, $value, $expiresAt, $this->remainingSeconds($expiresAt));

            return true;
        });
    }

    /**
     * @param string $name
     * @param Ttl $ttl
     * @return Lock
     */
    public function lock(string $name, Ttl $ttl): Lock
    {
        return new RedisLock($this->redis, $this->clock, $this->prefix . ':l:' . $name, $ttl);
    }

    /**
     * @param Key $key
     * @param callable(): mixed $operation
     * @return mixed
     */
    private function guarded(Key $key, callable $operation): mixed
    {
        $lock = new RedisLock(
            $this->redis,
            $this->clock,
            $this->prefix . ':lk:' . $this->keyEncoder->encode($key),
            Ttl::seconds(30),
        );
        $lock->block(5.0);

        try {
            return $operation();
        } finally {
            $lock->release();
        }
    }

    /**
     * @param Key $key
     * @param mixed $value
     * @param ?int $expiresAt
     * @param ?int $ttlSeconds
     */
    private function write(Key $key, mixed $value, ?int $expiresAt, ?int $ttlSeconds): void
    {
        $this->persist(StoredRecord::forKey($key, $this->clock->now(), $expiresAt, $this->codec->encode($value)), $ttlSeconds);
    }

    /**
     * @param StoredRecord $record
     * @param ?int $ttlSeconds
     */
    private function persist(StoredRecord $record, ?int $ttlSeconds): void
    {
        $this->redis->set(
            $this->entryKey($record->key()),
            $record->toString(),
            $ttlSeconds === null ? null : $ttlSeconds * 1000,
        );
    }

    /**
     * @param Key $key
     * @param ?string $raw
     * @return CacheEntry
     */
    private function hydrate(Key $key, ?string $raw): CacheEntry
    {
        $record = $raw === null ? null : StoredRecord::fromString($raw);

        if ($record === null || $this->isExpired($record->expiresAt)) {
            return CacheEntry::miss($key);
        }

        return CacheEntry::hit($key, $this->codec->decode($record->blob), $record->createdAt, $record->expiresAt);
    }

    /**
     * @param string $entryKey
     * @return ?StoredRecord
     */
    private function readEntry(string $entryKey): ?StoredRecord
    {
        $raw = $this->redis->get($entryKey);

        return $raw === null ? null : StoredRecord::fromString($raw);
    }

    /**
     * @param string $pattern
     */
    private function deleteByPattern(string $pattern): void
    {
        $keys = [];
        foreach ($this->redis->scan($pattern) as $key) {
            $keys[] = $key;
            if (count($keys) >= 200) {
                $this->redis->delete($keys);
                $keys = [];
            }
        }

        if ($keys !== []) {
            $this->redis->delete($keys);
        }
    }

    /**
     * @param ?int $expiresAt
     * @return ?int
     */
    private function remainingSeconds(?int $expiresAt): ?int
    {
        return $expiresAt === null ? null : max(1, $expiresAt - $this->clock->now());
    }

    /**
     * @param ?int $expiresAt
     * @return bool
     */
    private function isExpired(?int $expiresAt): bool
    {
        return $expiresAt !== null && $expiresAt <= $this->clock->now();
    }

    /**
     * @param Key $key
     * @return string
     */
    private function entryKey(Key $key): string
    {
        return $this->prefix . ':e:' . $this->keyEncoder->encode($key);
    }

    /**
     * @param string $tag
     * @return string
     */
    private function tagKey(string $tag): string
    {
        return $this->prefix . ':t:' . $tag;
    }

    /**
     * @param iterable<Key> $keys
     * @return list<Key>
     */
    private function keyList(iterable $keys): array
    {
        $list = [];
        foreach ($keys as $key) {
            $list[] = $key;
        }

        return $list;
    }
}
