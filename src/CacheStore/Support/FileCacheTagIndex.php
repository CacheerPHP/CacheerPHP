<?php

namespace Silviooosilva\CacheerPhp\CacheStore\Support;

use Silviooosilva\CacheerPhp\CacheStore\CacheManager\FileCacheManager;

/**
 * Manages tag membership for file-backed cache items.
 */
final class FileCacheTagIndex
{
    /**
     * @param FileCacheManager $fileManager
     * @param string $cacheDir
     * @param OperationStatus $status
     */
    public function __construct(
        private FileCacheManager $fileManager,
        private string $cacheDir,
        private OperationStatus $status
    ) {
    }

    /**
     * @param string $tag
     * @param string ...$keys
     * @return bool
     */
    public function tag(string $tag, string ...$keys): bool
    {
        $path = $this->tagIndexPath($tag);
        $current = [];
        if ($this->fileManager->fileExists($path)) {
            $json = $this->fileManager->readFile($path);
            $decoded = json_decode($json, true);
            if (is_array($decoded)) {
                $current = $decoded;
            }
        }
        foreach ($keys as $key) {
            $current[$key] = true;
        }
        $this->fileManager->writeFile($path, json_encode($current));
        $this->status->record("Tagged successfully", true);
        return true;
    }

    /**
     * @param string $tag
     * @param callable $clearCache Receives ($key, $namespace)
     * @return void
     */
    public function flush(string $tag, callable $clearCache): void
    {
        $path = $this->tagIndexPath($tag);
        $current = [];
        if ($this->fileManager->fileExists($path)) {
            $json = $this->fileManager->readFile($path);
            $current = json_decode($json, true) ?: [];
        }
        foreach (array_keys($current) as $key) {
            [$namespace, $cacheKey] = $this->splitKey($key);
            $clearCache($cacheKey, $namespace);
        }
        if ($this->fileManager->fileExists($path)) {
            $this->fileManager->removeFile($path);
        }
        $this->status->record("Tag flushed successfully", true);
    }

    /**
     * @param string $tag
     * @return string
     */
    private function tagIndexPath(string $tag): string
    {
        $tagDir = rtrim($this->cacheDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '_tags';
        if (!$this->fileManager->directoryExists($tagDir)) {
            $this->fileManager->createDirectory($tagDir);
        }
        return $tagDir . DIRECTORY_SEPARATOR . $tag . '.json';
    }

    /**
     * @param string $key
     * @return array{0:string,1:string}
     */
    private function splitKey(string $key): array
    {
        if (str_contains($key, ':')) {
            $parts = explode(':', $key, 2);
            return [$parts[0], $parts[1]];
        }
        return ['', $key];
    }
}
