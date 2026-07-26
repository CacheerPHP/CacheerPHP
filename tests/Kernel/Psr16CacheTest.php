<?php

declare(strict_types=1);

namespace Tests\Kernel;

use DateInterval;
use PHPUnit\Framework\TestCase;
use Psr\SimpleCache\CacheInterface;
use Psr\SimpleCache\InvalidArgumentException;
use Silviooosilva\CacheerPhp\Kernel\Cache;
use Silviooosilva\CacheerPhp\Psr\Psr16Cache;
use Silviooosilva\CacheerPhp\Stores\ArrayStore;
use Tests\Support\FakeClock;

final class Psr16CacheTest extends TestCase
{
    private FakeClock $clock;

    private Psr16Cache $psr;

    protected function setUp(): void
    {
        $this->clock = new FakeClock();
        $this->psr = new Psr16Cache(new Cache(new ArrayStore($this->clock), $this->clock));
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
}
