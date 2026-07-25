<?php

declare(strict_types=1);

namespace Tests\Contract;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Silviooosilva\CacheerPhp\Contracts\Store;
use Silviooosilva\CacheerPhp\Stores\FileStore;
use SplFileInfo;
use Tests\Support\FakeClock;
use Tests\Support\StoreConformance;

final class FileStoreConformanceTest extends StoreConformance
{
    private string $directory;

    protected function createStore(FakeClock $clock): Store
    {
        $this->directory = sys_get_temp_dir() . '/cacheer-file-conformance-' . bin2hex(random_bytes(6));

        return new FileStore($this->directory, clock: $clock);
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        if (!is_dir($this->directory)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            if ($item instanceof SplFileInfo) {
                $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
            }
        }

        @rmdir($this->directory);
    }
}
