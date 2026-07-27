<?php

declare(strict_types=1);

namespace Tests\Kernel;

use PHPUnit\Framework\TestCase;
use Silviooosilva\CacheerPhp\Compat\LegacyCacheer;
use Silviooosilva\CacheerPhp\Console\Application;
use Silviooosilva\CacheerPhp\Console\CacheerContext;
use Silviooosilva\CacheerPhp\Kernel\Cache;
use Silviooosilva\CacheerPhp\Psr\Psr16Cache;
use Silviooosilva\CacheerPhp\Psr\Psr6Pool;
use Silviooosilva\CacheerPhp\Stores\ArrayStore;
use Tests\Support\FakeClock;

/**
 * Milestone 8 release rehearsal: proves the two headline install paths work
 * end to end with nothing but the core package — no Redis, no database client,
 * no optional extensions beyond what a default PHP build ships.
 *
 * A green run here is the "fresh-install and v5-upgrade rehearsals pass in CI"
 * exit-gate for the 6.0 release candidate.
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
        $memory = Cache::inMemory();
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
        $file = Cache::file($this->dir);
        $file->set('persisted', 'ok');
        self::assertSame('ok', Cache::file($this->dir)->get('persisted'));
    }

    public function testV5UpgradePathBridgesThenReadsThroughTheModernApi(): void
    {
        // A v5 codebase keeps its call sites via the bridge...
        $legacy = LegacyCacheer::file($this->dir);
        self::assertTrue($legacy->putCache('user:1', ['name' => 'Ada'], 'accounts', 3600));
        self::assertSame(['name' => 'Ada'], $legacy->getCache('user:1', 'accounts'));

        // ...and the same data is readable through the v6 Cache API, so call
        // sites can be migrated incrementally against one shared store.
        $modern = Cache::file($this->dir);
        self::assertSame(['name' => 'Ada'], $modern->scope('accounts')->get('user:1'));
    }

    public function testPsrAdaptersResolveOverTheKernel(): void
    {
        $clock = new FakeClock();
        $cache = new Cache(new ArrayStore($clock), $clock);

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
