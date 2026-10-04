<?php

declare(strict_types=1);

namespace Silviooosilva\CacheerPhp\Stores;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
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
use Silviooosilva\CacheerPhp\Stores\Support\CounterArithmetic;
use Silviooosilva\CacheerPhp\Stores\Support\FileLock;
use Silviooosilva\CacheerPhp\Stores\Support\StoredRecord;
use Silviooosilva\CacheerPhp\Support\SystemClock;
use SplFileInfo;
use UnexpectedValueException;

/**
 * Dependency-free persistent store backed by the local filesystem.
 *
 * Values are encoded through the v6 storage pipeline and written atomically
 * (temp file + rename), so a reader never observes a half-written entry. Keys
 * are encoded to a bounded, traversal-safe filename, and every scan is confined
 * to this store's own directory. Locking, scoped clearing, tags, pruning, and
 * atomic counters are provided; scope invalidation is scan-based here (O(1)
 * generation tokens arrive in Milestone 5).
 */
final class FileStore implements
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
    private const ENTRIES_DIR = 'entries';

    private const TAGS_DIR = 'tags';

    /**
     * Per-entry list of the tags it belongs to (the reverse of TAGS_DIR), so an
     * entry that ends can leave its tags without scanning every tag file.
     */
    private const KEY_TAGS_DIR = 'keytags';

    private const LOCKS_DIR = 'locks';

    private const ENTRY_SUFFIX = '.cache';

    /**
     * @var string
     */
    private readonly string $root;

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
     * @param string $directory
     * @param ?EnvelopeCodec $codec
     * @param ?KeyEncoder $keyEncoder
     * @param ?Clock $clock
     * @param int $directoryPermissions
     */
    public function __construct(
        string $directory,
        ?EnvelopeCodec $codec = null,
        ?KeyEncoder $keyEncoder = null,
        ?Clock $clock = null,
        private readonly int $directoryPermissions = 0775,
    ) {
        $this->root = rtrim($directory, '/\\');
        $this->codec = $codec ?? PipelineConfig::default()->codec();
        $this->keyEncoder = $keyEncoder ?? new HashingKeyEncoder();
        $this->clock = $clock ?? new SystemClock();
        $this->ensureDir($this->root);
    }

    /**
     * @param Key $key
     * @return CacheEntry
     */
    public function get(Key $key): CacheEntry
    {
        $record = $this->read($this->pathFor($key));

        if ($record === null) {
            return CacheEntry::miss($key);
        }

        if ($this->isExpired($record->expiresAt)) {
            @unlink($this->pathFor($key));

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
        $this->write($key, $value, $ttl->expiresAt($this->clock));
    }

    /**
     * @param Key $key
     * @return bool
     */
    public function delete(Key $key): bool
    {
        $encoded = $this->keyEncoder->encode($key);
        $this->forgetTags($encoded);

        $path = $this->entryPath($encoded);

        return is_file($path) && @unlink($path);
    }

    public function clear(): void
    {
        $this->removeTree($this->root . '/' . self::ENTRIES_DIR);
        $this->removeTree($this->root . '/' . self::TAGS_DIR);
        $this->removeTree($this->root . '/' . self::KEY_TAGS_DIR);
    }

    /**
     * @param iterable<Key> $keys
     * @return list<CacheEntry>
     */
    public function getMany(iterable $keys): array
    {
        $entries = [];

        foreach ($keys as $key) {
            $entries[] = $this->get($key);
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

        foreach ($entries as $entry) {
            $this->write($entry['key'], $entry['value'], $expiresAt);
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
        $record = $this->read($this->pathFor($key));

        if ($record === null || $this->isExpired($record->expiresAt)) {
            return false;
        }

        $this->persist(StoredRecord::forKey($key, $record->createdAt, $ttl->expiresAt($this->clock), $record->blob));

        return true;
    }

    /**
     * @return int
     */
    public function prune(): int
    {
        $removed = 0;

        foreach ($this->entryFiles() as $file) {
            $record = $this->read($file->getPathname());
            if ($record !== null && $this->isExpired($record->expiresAt)) {
                @unlink($file->getPathname());
                $removed++;
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

        foreach ($this->entryFiles() as $file) {
            $record = $this->read($file->getPathname());
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

        foreach ($this->entryFiles() as $file) {
            $record = $this->read($file->getPathname());
            if ($record !== null && $scope->contains($record->key()->scope())) {
                $this->forgetTags($this->keyEncoder->encode($record->key()));
                @unlink($file->getPathname());
            }
        }
    }

    /**
     * @param Key $key
     * @param string ...$tags
     */
    public function tag(Key $key, string ...$tags): void
    {
        // Tags group stored entries; a key with no live entry has nothing to group.
        if ($this->get($key)->isMiss()) {
            return;
        }

        $encoded = $this->keyEncoder->encode($key);
        $membership = $this->membershipFile($encoded);
        $this->ensureDir(dirname($membership));

        foreach ($tags as $tag) {
            $file = $this->tagFile($tag);
            $this->ensureDir(dirname($file));
            @file_put_contents($file, $encoded . "\n", FILE_APPEND | LOCK_EX);
            @file_put_contents($membership, hash('sha256', $tag) . "\n", FILE_APPEND | LOCK_EX);
        }
    }

    /**
     * @param string $tag
     * @return int
     */
    public function clearTag(string $tag): int
    {
        $file = $this->tagFile($tag);
        if (!is_file($file)) {
            return 0;
        }

        $contents = @file_get_contents($file);
        if ($contents === false) {
            return 0;
        }

        $removed = 0;
        foreach (array_unique(array_filter(explode("\n", $contents))) as $encoded) {
            $path = $this->entryPath($encoded);
            if (is_file($path) && @unlink($path)) {
                $removed++;
            }

            // The entry is gone, so it leaves its other tags too. This tag's
            // own file is removed below, so it need not be rewritten first.
            $this->forgetTags($encoded, except: hash('sha256', $tag));
        }

        @unlink($file);

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
        return $this->withKeyLock('increment', $key, function () use ($key, $amount, $initial, $ttl): int {
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
                $next = CounterArithmetic::add($key, $current, $amount);
                $expiresAt = $ttl?->expiresAt($this->clock) ?? $entry->expiresAt();
            } else {
                $next = CounterArithmetic::add($key, $initial ?? 0, $amount);
                $expiresAt = $ttl?->expiresAt($this->clock);
            }

            $this->write($key, $next, $expiresAt);

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
        return $this->withKeyLock('compareAndSwap', $key, function () use ($key, $expected, $value, $ttl): bool {
            $entry = $this->get($key);

            if ($entry->isMiss() || $entry->value() !== $expected) {
                return false;
            }

            $this->write($key, $value, $ttl?->expiresAt($this->clock) ?? $entry->expiresAt());

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
        $dir = $this->root . '/' . self::LOCKS_DIR;
        $this->ensureDir($dir);

        return new FileLock($dir . '/' . hash('sha256', $name) . '.lock', $this->clock, $ttl);
    }

    /**
     * Runs a read-modify-write under this key's lock, and refuses to run it at
     * all when the lock cannot be acquired in time.
     *
     * @param string $name
     * @param Key $key
     * @param callable(): mixed $operation
     * @return mixed
     */
    private function withKeyLock(string $name, Key $key, callable $operation): mixed
    {
        $lock = $this->lock('entry:' . $this->keyEncoder->encode($key), Ttl::seconds(30));

        if (!$lock->block(5.0)) {
            throw new StoreOperationFailedException($name, $key, new RuntimeException('Timed out waiting for the key lock.'));
        }

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
     */
    private function write(Key $key, mixed $value, ?int $expiresAt): void
    {
        $this->persist(StoredRecord::forKey($key, $this->clock->now(), $expiresAt, $this->codec->encode($value)));
    }

    /**
     * @param StoredRecord $record
     */
    private function persist(StoredRecord $record): void
    {
        $path = $this->pathFor($record->key());
        $this->ensureDir(dirname($path));
        $this->atomicWrite($path, $record->toString());
    }

    /**
     * @param string $path
     * @param string $contents
     */
    private function atomicWrite(string $path, string $contents): void
    {
        $temp = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';

        if (@file_put_contents($temp, $contents, LOCK_EX) === false) {
            throw new RuntimeException(sprintf('Failed to write cache file "%s".', $path));
        }

        if (!@rename($temp, $path)) {
            @unlink($temp);

            throw new RuntimeException(sprintf('Failed to persist cache file "%s".', $path));
        }
    }

    /**
     * @param string $path
     * @return ?StoredRecord
     */
    private function read(string $path): ?StoredRecord
    {
        if (!is_file($path)) {
            return null;
        }

        $raw = @file_get_contents($path);
        if ($raw === false) {
            return null;
        }

        return StoredRecord::fromString($raw);
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
    private function pathFor(Key $key): string
    {
        return $this->entryPath($this->keyEncoder->encode($key));
    }

    /**
     * @param string $encoded
     * @return string
     */
    private function entryPath(string $encoded): string
    {
        $safe = hash('sha256', $encoded);

        return $this->root . '/' . self::ENTRIES_DIR . '/' . substr($safe, 0, 2) . '/' . $safe . self::ENTRY_SUFFIX;
    }

    /**
     * @param string $tag
     * @return string
     */
    private function tagFile(string $tag): string
    {
        return $this->tagFileFor(hash('sha256', $tag));
    }

    /**
     * @param string $tagHash
     * @return string
     */
    private function tagFileFor(string $tagHash): string
    {
        return $this->root . '/' . self::TAGS_DIR . '/' . $tagHash . '.tag';
    }

    /**
     * @param string $encoded
     * @return string
     */
    private function membershipFile(string $encoded): string
    {
        $safe = hash('sha256', $encoded);

        return $this->root . '/' . self::KEY_TAGS_DIR . '/' . substr($safe, 0, 2) . '/' . $safe . '.tags';
    }

    /**
     * Ends an entry's tag membership: removes the entry from every tag file its
     * membership file lists, then the membership file itself.
     *
     * @param string $encoded
     * @param ?string $except a tag hash whose file the caller is deleting anyway
     */
    private function forgetTags(string $encoded, ?string $except = null): void
    {
        $membership = $this->membershipFile($encoded);
        if (!is_file($membership)) {
            return;
        }

        $contents = @file_get_contents($membership);
        foreach (array_unique(array_filter(explode("\n", (string) $contents))) as $tagHash) {
            if ($tagHash !== $except) {
                $this->removeTagLine($this->tagFileFor($tagHash), $encoded);
            }
        }

        @unlink($membership);
    }

    /**
     * @param string $file
     * @param string $encoded
     */
    private function removeTagLine(string $file, string $encoded): void
    {
        $handle = @fopen($file, 'c+');
        if ($handle === false) {
            return;
        }

        try {
            if (!@flock($handle, LOCK_EX)) {
                return;
            }

            $lines = explode("\n", (string) stream_get_contents($handle));
            $kept = array_filter($lines, static fn (string $line): bool => $line !== '' && $line !== $encoded);

            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, $kept === [] ? '' : implode("\n", $kept) . "\n");
            fflush($handle);
            flock($handle, LOCK_UN);
        } finally {
            fclose($handle);
        }
    }

    /**
     * @return iterable<SplFileInfo>
     */
    private function entryFiles(): iterable
    {
        $dir = $this->root . '/' . self::ENTRIES_DIR;
        if (!is_dir($dir)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo && $file->isFile() && str_ends_with($file->getFilename(), self::ENTRY_SUFFIX)) {
                yield $file;
            }
        }
    }

    /**
     * @param string $dir
     */
    private function ensureDir(string $dir): void
    {
        if (!is_dir($dir) && !@mkdir($dir, $this->directoryPermissions, true) && !is_dir($dir)) {
            throw new RuntimeException(sprintf('Failed to create cache directory "%s".', $dir));
        }
    }

    /**
     * @param string $dir
     */
    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            if ($item instanceof SplFileInfo) {
                $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
            }
        }
    }
}
