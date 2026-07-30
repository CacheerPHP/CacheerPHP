<?php

declare(strict_types=1);

namespace Tests\Kernel;

use PHPUnit\Framework\TestCase;
use Silviooosilva\CacheerPhp\Cacheer;
use Silviooosilva\CacheerPhp\Config\PipelineConfig;
use Silviooosilva\CacheerPhp\Console\Application;
use Silviooosilva\CacheerPhp\Console\CacheerContext;
use Silviooosilva\CacheerPhp\Kernel\Key;
use Silviooosilva\CacheerPhp\Psr\Psr16Cache;
use Silviooosilva\CacheerPhp\Psr\Psr6Pool;
use Silviooosilva\CacheerPhp\Storage\Compat\V5PayloadReader;
use Silviooosilva\CacheerPhp\Storage\Envelope;
use Silviooosilva\CacheerPhp\Storage\KeyEncoder\HashingKeyEncoder;
use Silviooosilva\CacheerPhp\Stores\ArrayStore;
use Silviooosilva\CacheerPhp\Stores\FileStore;
use Silviooosilva\CacheerPhp\Stores\Support\StoredRecord;
use Tests\Support\FakeClock;

/**
 * Release rehearsal: proves the headline paths work end to end with nothing but
 * the core package — no Redis, no database client, no optional extensions beyond
 * what a default PHP build ships.
 *
 * A green run here is the "fresh-install and v5-upgrade rehearsals pass in CI"
 * exit-gate for the 6.0 release candidate. The v5-upgrade path is the data
 * bridge (rewrite-on-read), not an API shim.
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

    public function testV5DataUpgradesThroughRewriteOnRead(): void
    {
        $clock = new FakeClock();
        $key = Key::named('legacy:value');

        // Seed a value in the v5 on-disk format (an untransformed payload).
        $codec = PipelineConfig::default()->withV5Reader(new V5PayloadReader())->codec();
        $encoder = new HashingKeyEncoder();
        $safe = hash('sha256', $encoder->encode($key));
        $path = $this->dir . '/entries/' . substr($safe, 0, 2) . '/' . $safe . '.cache';
        @mkdir(dirname($path), 0775, true);
        file_put_contents($path, StoredRecord::forKey($key, 1_000, null, 'legacy-value')->toString());

        // A store told to migrate legacy data reads it and rewrites it as v6.
        $cache = new Cacheer(new FileStore($this->dir, $codec, clock: $clock, migrateLegacyOnRead: true), $clock);
        self::assertSame('legacy-value', $cache->get('legacy:value'));

        // The entry on disk is now a v6 envelope — the modern API owns it.
        $record = StoredRecord::fromString((string) file_get_contents($path));
        self::assertNotNull($record);
        self::assertTrue(Envelope::isEnvelope($record->blob));
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
