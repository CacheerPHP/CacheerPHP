<?php

declare(strict_types=1);

namespace Tests\Kernel;

use PHPUnit\Framework\TestCase;
use Silviooosilva\CacheerPhp\Cacheer;
use Silviooosilva\CacheerPhp\Config\CacheerBuilder;
use Silviooosilva\CacheerPhp\Support\SystemClock;
use Tests\Support\FakeClock;

final class CacheerBuilderTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/cacheer-builder-' . bin2hex(random_bytes(6));
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

    public function testBuildReturnsABuilder(): void
    {
        self::assertInstanceOf(CacheerBuilder::class, Cacheer::build());
    }

    public function testInMemoryWithoutPolicyReturnsAPlainCacheer(): void
    {
        $cache = Cacheer::build()->inMemory()->create();

        self::assertInstanceOf(Cacheer::class, $cache);
        $cache->set('k', ['v' => 1]);
        self::assertSame(['v' => 1], $cache->get('k'));
    }

    public function testFileWithGzipRoundTrips(): void
    {
        $cache = Cacheer::build()
            ->file($this->dir)
            ->gzip()
            ->maxValueBytes(1_000_000)
            ->create();

        $payload = array_fill(0, 200, 'compress me');
        $cache->set('big', $payload);

        self::assertSame($payload, Cacheer::build()->file($this->dir)->gzip()->create()->get('big'));
    }

    public function testEncryptionRoundTrips(): void
    {
        $build = fn () => Cacheer::build()
            ->file($this->dir)
            ->encryptWithPassphrases(['current' => 'a-strong-secret'], 'current')
            ->create();

        $build()->set('token', 'sensitive-data');
        self::assertSame('sensitive-data', $build()->get('token'));
    }

    public function testPolicyMethodsBindThePolicyAndApplyTheDefaultTtl(): void
    {
        $clock = new FakeClock();

        $cache = Cacheer::build()
            ->inMemory()
            ->clock($clock)
            ->defaultTtl(60)
            ->jitter(0.0)
            ->create();

        self::assertInstanceOf(Cacheer::class, $cache);
        self::assertTrue($cache->stats()['policy']);

        // No per-call TTL → the policy's default (60s) applies.
        $cache->set('k', 'v');
        self::assertSame('v', $cache->get('k'));

        $clock->advance(59);
        self::assertSame('v', $cache->get('k'));

        $clock->advance(2);
        self::assertNull($cache->get('k'));
    }

    public function testClockIsInjected(): void
    {
        $cache = Cacheer::build()->file($this->dir)->clock(new SystemClock())->create();
        $cache->set('k', 'v', 60);

        self::assertSame('v', $cache->get('k'));
    }
}
