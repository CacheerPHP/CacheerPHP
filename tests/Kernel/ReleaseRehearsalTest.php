<?php

declare(strict_types=1);

namespace Tests\Kernel;

use PHPUnit\Framework\TestCase;
use Silviooosilva\CacheerPhp\Cacheer;
use Silviooosilva\CacheerPhp\Console\Application;
use Silviooosilva\CacheerPhp\Console\CacheerContext;
use Silviooosilva\CacheerPhp\Psr\Psr16Cache;
use Silviooosilva\CacheerPhp\Psr\Psr6Pool;
use Silviooosilva\CacheerPhp\Stores\ArrayStore;
use Tests\Support\FakeClock;

/**
 * Release rehearsal: proves the headline paths work end to end with nothing but
 * the core package — no Redis, no database client, no optional extensions beyond
 * what a default PHP build ships.
 *
 * A green run here is the "fresh-install and v5-upgrade rehearsals pass in CI"
 * check for the 6.0 stable release. The v5-upgrade path starts with a cold
 * cache in a separate keyspace, as documented in MIGRATION.md.
 */
final class ReleaseRehearsalTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/cacheer-rehearsal-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->dir)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($this->dir);
    }

    public function testFreshInstallWorksWithZeroOptionalDependencies(): void
    {
        // In-memory: the dependency-free default.
        $memory = Cacheer::inMemory();
        $memory->set('k', ['v' => 1], ttl: 60);
        self::assertSame(['v' => 1], $memory->get('k'));

        $calls = 0;
        $value = $memory->remember('report', 60, function () use (&$calls) {
            $calls++;

            return 'built';
        });
        self::assertSame('built', $value);
        self::assertSame('built', $memory->remember('report', 60, fn () => 'other'));
        self::assertSame(1, $calls);

        self::assertSame('billing-only', $memory->scope('billing')->remember('x', 60, fn () => 'billing-only'));
        self::assertNull($memory->get('x'));

        // Filesystem: persistent, still dependency-free.
        $file = Cacheer::file($this->dir);
        $file->set('persisted', 'ok');
        self::assertSame('ok', Cacheer::file($this->dir)->get('persisted'));
    }

    public function testV6StartsColdBesideV5DataAndLeavesItForRollback(): void
    {
        // A value exactly as v5.2's FileCacheStore wrote it: md5(key).cache
        // holding a serialized envelope, directly under the cache directory.
        $v5File = $this->dir . '/' . md5('legacy:value') . '.cache';
        @mkdir($this->dir, 0775, true);
        file_put_contents($v5File, serialize(['data' => 'legacy-value', 'expires_at' => PHP_INT_MAX, 'ttl' => 3600]));

        // v6 uses its own layout, so the upgrade starts cold rather than
        // misreading v5 data.
        $cache = Cacheer::file($this->dir);
        self::assertNull($cache->get('legacy:value'));

        $cache->set('legacy:value', 'v6-value');
        self::assertSame('v6-value', $cache->get('legacy:value'));

        // Clearing v6 never touches v5's files, so rolling back still finds them.
        $cache->clear();
        self::assertFileExists($v5File);
    }

    public function testPsrAdaptersResolveOverTheKernel(): void
    {
        $clock = new FakeClock();
        $cache = new Cacheer(new ArrayStore($clock), $clock);

        $psr16 = new Psr16Cache($cache);
        $psr16->set('key', 'value', 3600);
        self::assertSame('value', $psr16->get('key'));
        self::assertTrue($psr16->has('key'));
        self::assertSame('default', $psr16->get('missing', 'default'));

        $pool = new Psr6Pool($cache, $clock);
        $item = $pool->getItem('poolkey');
        self::assertFalse($item->isHit());
        $item->set(42)->expiresAfter(60);
        $pool->save($item);
        self::assertTrue($pool->getItem('poolkey')->isHit());
        self::assertSame(42, $pool->getItem('poolkey')->get());
    }

    public function testCliRunsWithoutAConfig(): void
    {
        $app = new Application();

        ob_start();
        $doctor = $app->run(['cacheer', 'doctor', '--json']);
        $doctorOutput = (string) ob_get_clean();

        self::assertSame(0, $doctor);
        self::assertStringContainsString('"healthy"', $doctorOutput);

        // With an injected store context, stats reports the store name.
        $context = new CacheerContext(new ArrayStore(new FakeClock()));
        ob_start();
        $stats = $app->run(['cacheer', 'stats', '--json'], $context);
        $statsOutput = (string) ob_get_clean();

        self::assertSame(0, $stats);
        self::assertStringContainsString('ArrayStore', $statsOutput);
    }
}
