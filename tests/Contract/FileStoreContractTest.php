<?php

declare(strict_types=1);

namespace Tests\Contract;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Silviooosilva\CacheerPhp\Cacheer;
use Tests\Support\FakeClock;

final class FileStoreContractTest extends StoreContractTestCase
{
    private string $directory;

    protected function createCache(FakeClock $clock): Cacheer
    {
        $this->directory = sys_get_temp_dir() . '/cacheer-contract-file-' . bin2hex(random_bytes(4));

        return new Cacheer(['cacheDir' => $this->directory, 'clock' => $clock]);
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
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }

        @rmdir($this->directory);
    }
}
