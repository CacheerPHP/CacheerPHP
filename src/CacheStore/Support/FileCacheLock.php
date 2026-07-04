<?php

namespace Silviooosilva\CacheerPhp\CacheStore\Support;

use Silviooosilva\CacheerPhp\CacheStore\CacheManager\FileCacheManager;

/**
 * Class FileCacheLock
 *
 * flock-based mutual-exclusion locks for the file cache driver. Each lock is a
 * dedicated file under a "cacheer-locks" subdirectory of the cache directory;
 * the exclusive flock is held until release() (or process exit, which
 * auto-releases — so locks never orphan).
 *
 * @author Sílvio Silva <https://github.com/silviooosilva>
 * @package Silviooosilva\CacheerPhp
 */
class FileCacheLock
{
    /**
     * @var FileCacheManager
     */
    private FileCacheManager $fileManager;

    /**
     * @var string
     */
    private string $cacheDir;

    /**
     * Held flock handles: name => ['handle' => resource, 'owner' => string].
     *
     * @var array<string,array{handle:resource,owner:string}>
     */
    private array $lockHandles = [];

    /**
     * @param FileCacheManager $fileManager
     * @param string           $cacheDir Directory the cache (and its lock files) live in.
     */
    public function __construct(FileCacheManager $fileManager, string $cacheDir)
    {
        $this->fileManager = $fileManager;
        $this->cacheDir = $cacheDir;
    }

    /**
     * Acquire a cross-process lock via an exclusive, non-blocking flock on a
     * dedicated lock file. The handle is held until release() (or process exit,
     * which auto-releases — so locks never orphan).
     *
     * @param string $name
     * @param string $owner
     * @param int    $ttl
     * @return bool
     */
    public function acquire(string $name, string $owner, int $ttl): bool
    {
        $existing = $this->lockHandles[$name] ?? null;
        if ($existing !== null) {
            // Already held in this process: re-entrant for the same owner only.
            return $existing['owner'] === $owner;
        }

        $handle = @fopen($this->fileManager->lockFilePath($name, $this->cacheDir), 'c');
        if (!$handle) {
            return false;
        }

        if (!@flock($handle, LOCK_EX | LOCK_NB)) {
            @fclose($handle);
            return false;
        }

        @ftruncate($handle, 0);
        @fwrite($handle, $owner . '|' . (time() + max(1, $ttl)));
        @fflush($handle);
        $this->lockHandles[$name] = ['handle' => $handle, 'owner' => $owner];

        return true;
    }

    /**
     * Release a held flock lock if owned by $owner.
     *
     * @param string $name
     * @param string $owner
     * @return bool
     */
    public function release(string $name, string $owner): bool
    {
        $held = $this->lockHandles[$name] ?? null;
        if (is_null($held) || $held['owner'] !== $owner) {
            return false;
        }

        @flock($held['handle'], LOCK_UN);
        @fclose($held['handle']);
        // Intentionally do NOT unlink the lock file: deleting it lets a new
        // acquirer create a fresh inode and lock it independently of a process
        // still holding the old one, breaking mutual exclusion. The lock file
        // is a stable, reusable rendezvous point.
        unset($this->lockHandles[$name]);

        return true;
    }
}
