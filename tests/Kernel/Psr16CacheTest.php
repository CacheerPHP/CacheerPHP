<?php

declare(strict_types=1);

namespace Tests\Kernel;

use DateInterval;
use PHPUnit\Framework\TestCase;
use Psr\SimpleCache\CacheInterface;
use Psr\SimpleCache\InvalidArgumentException;
use Silviooosilva\CacheerPhp\Cacheer;
use Silviooosilva\CacheerPhp\Psr\Psr16Cache;
use Silviooosilva\CacheerPhp\Stores\ArrayStore;
use Tests\Support\FakeClock;
use Tests\Support\ToggleableStore;

final class Psr16CacheTest extends TestCase
{
    private FakeClock $clock;

    private Psr16Cache $psr;

    protected function setUp(): void
    {
        $this->clock = new FakeClock();
        $this->psr = new Psr16Cache(new Cacheer(new ArrayStore($this->clock), $this->clock));
    }

    public function testItIsAPsr16Cache(): void
    {
        self::assertInstanceOf(CacheInterface::class, $this->psr);
    }

    public function testSetGetHasDeleteAndDefault(): void
    {
        self::assertSame('fallback', $this->psr->get('missing', 'fallback'));
        self::assertFalse($this->psr->has('missing'));

        self::assertTrue($this->psr->set('k', ['v' => 1]));
        self::assertTrue($this->psr->has('k'));
        self::assertSame(['v' => 1], $this->psr->get('k'));

        self::assertTrue($this->psr->delete('k'));
        self::assertFalse($this->psr->has('k'));
    }

    public function testCachedNullIsAHitDistinctFromTheDefault(): void
    {
        $this->psr->set('nullable', null);

        self::assertNull($this->psr->get('nullable', 'default'));
        self::assertTrue($this->psr->has('nullable'));
    }

    public function testTtlExpiresAndNonPositiveTtlDeletes(): void
    {
        $this->psr->set('ttl', 'v', 10);
        $this->clock->advance(11);
        self::assertFalse($this->psr->has('ttl'));

        $this->psr->set('present', 'v');
        self::assertTrue($this->psr->set('present', 'v', 0), 'A non-positive TTL must delete and still succeed.');
        self::assertFalse($this->psr->has('present'));
    }

    public function testDateIntervalTtl(): void
    {
        $this->psr->set('k', 'v', new DateInterval('PT30S'));
        $this->clock->advance(20);
        self::assertTrue($this->psr->has('k'));
        $this->clock->advance(11);
        self::assertFalse($this->psr->has('k'));
    }

    public function testMultipleOperations(): void
    {
        self::assertTrue($this->psr->setMultiple(['a' => 1, 'b' => 2, 'c' => 3]));

        self::assertSame(
            ['a' => 1, 'x' => 'none', 'b' => 2],
            $this->psr->getMultiple(['a', 'x', 'b'], 'none'),
        );

        self::assertTrue($this->psr->deleteMultiple(['a', 'b']));
        self::assertFalse($this->psr->has('a'));
        self::assertTrue($this->psr->has('c'));
    }

    public function testReservedCharactersAreRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->psr->get('bad{key}');
    }

    public function testEmptyKeyIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->psr->set('', 'v');
    }

    public function testInvalidNativeKeysBecomePsrExceptions(): void
    {
        $long = str_repeat('k', 1025);

        foreach ([
            'get'            => static fn (Psr16Cache $c): mixed => $c->get($long),
            'set'            => static fn (Psr16Cache $c): mixed => $c->set("a\x01b", 'v'),
            'has'            => static fn (Psr16Cache $c): mixed => $c->has("a\nb"),
            'delete'         => static fn (Psr16Cache $c): mixed => $c->delete($long),
            'getMultiple'    => static fn (Psr16Cache $c): mixed => $c->getMultiple(['ok', $long]),
            'non-string key' => static fn (Psr16Cache $c): mixed => $c->getMultiple(['ok', null]),
            'setMultiple'    => static fn (Psr16Cache $c): mixed => $c->setMultiple([$long => 'v']),
            'deleteMultiple' => static fn (Psr16Cache $c): mixed => $c->deleteMultiple([['nested']]),
        ] as $case => $attempt) {
            try {
                $attempt($this->psr);
                self::fail(sprintf('%s: an invalid key must be rejected.', $case));
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testATtlBeyondThePlatformLimitIsAPsrException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->psr->set('k', 'v', PHP_INT_MAX);
    }

    public function testStoreFailuresAreReportedAsFalse(): void
    {
        $store = new ToggleableStore(new ArrayStore($this->clock));
        $psr = new Psr16Cache(new Cacheer($store, $this->clock));
        $store->failing = true;

        self::assertFalse($psr->set('k', 'v'));
        self::assertFalse($psr->delete('k'));
        self::assertFalse($psr->clear());
        self::assertFalse($psr->setMultiple(['a' => 1, 'b' => 2]));
        self::assertFalse($psr->deleteMultiple(['a', 'b']));
    }

    public function testFalsyValuesKeepTheirTypeThroughAPersistentStore(): void
    {
        $dir = sys_get_temp_dir() . '/cacheer-psr16-' . bin2hex(random_bytes(4));
        $psr = new Psr16Cache(Cacheer::file($dir));

        try {
            foreach (['false' => false, 'zero' => 0, 'float' => 0.0, 'empty' => '', 'array' => [], 'null' => null] as $key => $value) {
                self::assertTrue($psr->set($key, $value));
                self::assertSame($value, $psr->get($key, 'default'), sprintf('"%s" must round-trip exactly.', $key));
                self::assertTrue($psr->has($key));
            }
        } finally {
            Cacheer::file($dir)->clear();
            @rmdir($dir . '/entries');
            @rmdir($dir);
        }
    }
}
