<?php

declare(strict_types=1);

namespace Tests\Kernel;

use DateInterval;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Cache\InvalidArgumentException;
use Silviooosilva\CacheerPhp\Cacheer;
use Silviooosilva\CacheerPhp\Psr\Psr6Pool;
use Silviooosilva\CacheerPhp\Stores\ArrayStore;
use Tests\Support\FakeClock;
use Tests\Support\ToggleableStore;

final class Psr6PoolTest extends TestCase
{
    private FakeClock $clock;

    private Psr6Pool $pool;

    protected function setUp(): void
    {
        $this->clock = new FakeClock();
        $this->pool = new Psr6Pool(new Cacheer(new ArrayStore($this->clock), $this->clock), $this->clock);
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

    public function testADeferredItemReadsAsAHitBeforeCommit(): void
    {
        $item = $this->pool->getItem('k');
        self::assertFalse($item->isHit());

        $this->pool->saveDeferred($item->set('deferred'));

        $read = $this->pool->getItem('k');
        self::assertTrue($read->isHit(), 'A deferred item is visible before commit.');
        self::assertSame('deferred', $read->get());
        self::assertTrue($this->pool->hasItem('k'));

        self::assertTrue($this->pool->commit());
        self::assertSame('deferred', $this->pool->getItem('k')->get());
    }

    public function testMutatingAnItemAfterSaveDeferredDoesNotChangeTheQueuedValue(): void
    {
        $item = $this->pool->getItem('k')->set('queued');
        $this->pool->saveDeferred($item);
        $item->set('changed afterwards');

        self::assertSame('queued', $this->pool->getItem('k')->get());
        $this->pool->getItem('k')->set('changed through a read');
        self::assertSame('queued', $this->pool->getItem('k')->get());

        $this->pool->commit();
        self::assertSame('queued', $this->pool->getItem('k')->get());
    }

    public function testADeferredItemExpiresWhileDeferred(): void
    {
        $this->pool->saveDeferred($this->pool->getItem('k')->set('v')->expiresAfter(10));
        $this->clock->advance(20);

        self::assertFalse($this->pool->getItem('k')->isHit());
        self::assertFalse($this->pool->hasItem('k'));

        $this->pool->commit();
        self::assertFalse($this->pool->getItem('k')->isHit(), 'Its lifetime started at saveDeferred(), not at commit().');
    }

    public function testDeleteAndClearDiscardDeferredItems(): void
    {
        $this->pool->saveDeferred($this->pool->getItem('a')->set(1));
        $this->pool->saveDeferred($this->pool->getItem('b')->set(2));

        $this->pool->deleteItem('a');
        self::assertFalse($this->pool->getItem('a')->isHit());
        $this->pool->clear();
        self::assertFalse($this->pool->getItem('b')->isHit());

        $this->pool->commit();
        self::assertFalse($this->pool->hasItem('a'));
        self::assertFalse($this->pool->hasItem('b'));
    }

    public function testInvalidNativeKeysBecomePsrExceptions(): void
    {
        foreach ([
            'too long'     => static fn (Psr6Pool $pool): mixed => $pool->getItem(str_repeat('k', 1025)),
            'control char' => static fn (Psr6Pool $pool): mixed => $pool->hasItem("a\x01b"),
            'delete'       => static fn (Psr6Pool $pool): mixed => $pool->deleteItem("a\nb"),
            'non-string'   => static fn (Psr6Pool $pool): mixed => $pool->getItems([1]),
            'deleteItems'  => static fn (Psr6Pool $pool): mixed => $pool->deleteItems([str_repeat('k', 1025)]),
        ] as $case => $attempt) {
            try {
                $attempt($this->pool);
                self::fail(sprintf('%s: an invalid key must be rejected.', $case));
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testStoreFailuresAreReportedAsFalse(): void
    {
        $store = new ToggleableStore(new ArrayStore($this->clock));
        $pool = new Psr6Pool(new Cacheer($store, $this->clock), $this->clock);
        $item = $pool->getItem('k')->set('v');
        $store->failing = true;

        self::assertFalse($pool->save($item));
        self::assertFalse($pool->deleteItem('k'));
        self::assertFalse($pool->clear());
        self::assertTrue($pool->saveDeferred($item));
        self::assertFalse($pool->commit());
    }
}
