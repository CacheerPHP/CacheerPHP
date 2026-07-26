<?php

declare(strict_types=1);

namespace Tests\Kernel;

use DateInterval;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Cache\InvalidArgumentException;
use Silviooosilva\CacheerPhp\Kernel\Cache;
use Silviooosilva\CacheerPhp\Psr\Psr6Pool;
use Silviooosilva\CacheerPhp\Stores\ArrayStore;
use Tests\Support\FakeClock;

final class Psr6PoolTest extends TestCase
{
    private FakeClock $clock;

    private Psr6Pool $pool;

    protected function setUp(): void
    {
        $this->clock = new FakeClock();
        $this->pool = new Psr6Pool(new Cache(new ArrayStore($this->clock), $this->clock), $this->clock);
    }

    public function testItIsAPsr6Pool(): void
    {
        self::assertInstanceOf(CacheItemPoolInterface::class, $this->pool);
    }

    public function testSaveGetAndMissSemantics(): void
    {
        $miss = $this->pool->getItem('k');
        self::assertFalse($miss->isHit());
        self::assertNull($miss->get());

        $item = $this->pool->getItem('k')->set(['v' => 1]);
        self::assertTrue($this->pool->save($item));

        $hit = $this->pool->getItem('k');
        self::assertTrue($hit->isHit());
        self::assertSame(['v' => 1], $hit->get());
        self::assertTrue($this->pool->hasItem('k'));
    }

    public function testExpirationConvertsToTtl(): void
    {
        $item = $this->pool->getItem('k')->set('v')->expiresAfter(new DateInterval('PT30S'));
        $this->pool->save($item);

        $this->clock->advance(20);
        self::assertTrue($this->pool->hasItem('k'));

        $this->clock->advance(11);
        self::assertFalse($this->pool->hasItem('k'));
    }

    public function testAlreadyExpiredItemIsDeletedOnSave(): void
    {
        $this->pool->save($this->pool->getItem('k')->set('v'));
        $item = $this->pool->getItem('k')->set('v')->expiresAfter(-5);

        self::assertTrue($this->pool->save($item));
        self::assertFalse($this->pool->hasItem('k'));
    }

    public function testDeferredSavesArePersistedOnCommit(): void
    {
        $this->pool->saveDeferred($this->pool->getItem('a')->set(1));
        $this->pool->saveDeferred($this->pool->getItem('b')->set(2));

        // Visible within the pool before commit ...
        self::assertTrue($this->pool->hasItem('a'));

        self::assertTrue($this->pool->commit());

        // ... and persisted to a fresh pool over the same store afterward.
        self::assertSame(1, $this->pool->getItem('a')->get());
        self::assertSame(2, $this->pool->getItem('b')->get());
    }

    public function testDeleteAndClear(): void
    {
        $this->pool->save($this->pool->getItem('a')->set(1));
        $this->pool->save($this->pool->getItem('b')->set(2));

        self::assertTrue($this->pool->deleteItem('a'));
        self::assertFalse($this->pool->hasItem('a'));

        $this->pool->clear();
        self::assertFalse($this->pool->hasItem('b'));
    }

    public function testReservedCharactersAreRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->pool->getItem('bad:key');
    }
}
