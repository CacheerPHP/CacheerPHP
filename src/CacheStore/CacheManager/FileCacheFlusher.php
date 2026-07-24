<?php

namespace Silviooosilva\CacheerPhp\CacheStore\CacheManager;

use Silviooosilva\CacheerPhp\Contracts\Clock;
use Silviooosilva\CacheerPhp\Helpers\CacheerHelper;
use Silviooosilva\CacheerPhp\Support\SystemClock;

/**
 * Class FileCacheFlusher
 * @author Sílvio Silva <https://github.com/silviooosilva>
 * @package Silviooosilva\CacheerPhp
 */
class FileCacheFlusher
{
    /**
    * @var FileCacheManager
    */
    private FileCacheManager $fileManager;

    /**
    * @var string $cacheDir
    */
    private string $cacheDir;

    /**
    * @var string $lastFlushTimeFile
    */
    private string $lastFlushTimeFile;

    private Clock $clock;

    /**
     * FileCacheFlusher constructor.
     *
     * @param FileCacheManager $fileManager
     * @param string $cacheDir
     */
    public function __construct(FileCacheManager $fileManager, string $cacheDir, ?Clock $clock = null)
    {
        $this->fileManager = $fileManager;
        $this->cacheDir = $cacheDir;
        $this->lastFlushTimeFile = "$cacheDir/last_flush_time";
        $this->clock = $clock ?? new SystemClock();
    }

    /**
    * Flushes all cache items and updates the last flush timestamp.
    *
    * @return void
    */
    public function flushCache(): void
    {
        $this->fileManager->clearDirectory($this->cacheDir);
        file_put_contents($this->lastFlushTimeFile, $this->clock->now());
    }

    /**
    * Handles the auto-flush functionality based on options.
    *
    * @param array $options
    * @return void
    */
    public function handleAutoFlush(array $options): void
    {
        if (isset($options['flushAfter'])) {
            $this->scheduleFlush($options['flushAfter']);
        }
    }

    /**
     * Schedules a flush operation based on the provided interval.
     *
     * @param string $flushAfter
     * @return void
     * @throws \InvalidArgumentException
     */
    private function scheduleFlush(string $flushAfter): void
    {
        $flushAfterSeconds = CacheerHelper::convertExpirationToSeconds($flushAfter);

        if (!$this->fileManager->fileExists($this->lastFlushTimeFile)) {
            $this->fileManager->writeFile($this->lastFlushTimeFile, (string) $this->clock->now());
            return;
        }

        $lastFlushTime = (int) $this->fileManager->readFile($this->lastFlushTimeFile);

        if (($this->clock->now() - $lastFlushTime) >= $flushAfterSeconds) {
            $this->flushCache();
            $this->fileManager->writeFile($this->lastFlushTimeFile, (string) $this->clock->now());
        }
    }
}
