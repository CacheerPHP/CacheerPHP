<?php

declare(strict_types=1);

namespace Tests\Contract;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Silviooosilva\CacheerPhp\Kernel\Key;
use Silviooosilva\CacheerPhp\Kernel\Ttl;
use Silviooosilva\CacheerPhp\Stores\FileStore;
use SplFileInfo;
use Tests\Support\FakeClock;

final class FileStoreHardeningTest extends TestCase
{
    private string $directory;

    private FileStore $store;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/cacheer-file-hardening-' . bin2hex(random_bytes(6));
        $this->store = new FileStore($this->directory, clock: new FakeClock());
    }

    protected function tearDown(): void
    {
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

    public function testTraversalStyleKeysStayInsideTheCacheDirectory(): void
    {
        $key = Key::named('../../../etc/passwd');
        $this->store->set($key, 'contained', Ttl::forever());

        self::assertSame('contained', $this->store->get($key)->value());

        foreach ($this->allFiles() as $file) {
            self::assertStringStartsWith(
                realpath($this->directory) ?: $this->directory,
                realpath($file) ?: $file,
                'Every cache file must live inside the cache directory.',
            );
        }
    }

    public function testLongKeysAreHashedToBoundedFilenames(): void
    {
        $key = Key::named(str_repeat('k', 1000));
        $this->store->set($key, 'value', Ttl::forever());

        self::assertSame('value', $this->store->get($key)->value());
    }

    public function testWritesAreAtomicAndLeaveNoTemporaryFiles(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $this->store->set(Key::named('k' . $i), str_repeat('v', 500), Ttl::forever());
        }

        foreach ($this->allFiles() as $file) {
            self::assertStringEndsNotWith('.tmp', $file, 'A committed write must leave no temp file behind.');
        }
    }

    /**
     * @return list<string>
     */
    private function allFiles(): array
    {
        if (!is_dir($this->directory)) {
            return [];
        }

        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->directory, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $item) {
            if ($item instanceof SplFileInfo && $item->isFile()) {
                $files[] = $item->getPathname();
            }
        }

        return $files;
    }
}
