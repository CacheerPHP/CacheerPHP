<?php

declare(strict_types=1);

namespace Tests\Contract;

use FilesystemIterator;
use PDO;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Silviooosilva\CacheerPhp\Kernel\Cache;
use Silviooosilva\CacheerPhp\Stores\Support\DatabaseStoreSchema;
use SplFileInfo;
use Tests\Support\FakeClock;

final class NamedConstructorsTest extends TestCase
{
    public function testInMemoryConstructorRoundTrips(): void
    {
        $cache = Cache::inMemory(new FakeClock());
        $cache->set('k', ['v' => 1]);

        self::assertSame(['v' => 1], $cache->get('k'));
    }

    public function testFileConstructorRoundTrips(): void
    {
        $directory = sys_get_temp_dir() . '/cacheer-named-file-' . bin2hex(random_bytes(6));
        $cache = Cache::file($directory, clock: new FakeClock());

        $cache->set('user:1', 'Ada', '10 minutes');
        self::assertSame('Ada', $cache->get('user:1'));

        $this->removeTree($directory);
    }

    public function testDatabaseConstructorRoundTrips(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        DatabaseStoreSchema::migrate($pdo, 'cacheer_store');

        $cache = Cache::database($pdo, clock: new FakeClock());
        $cache->set('k', 42);

        self::assertSame(42, $cache->get('k'));
    }

    private function removeTree(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            if ($item instanceof SplFileInfo) {
                $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
            }
        }

        @rmdir($directory);
    }
}
