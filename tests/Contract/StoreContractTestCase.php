<?php

declare(strict_types=1);

namespace Tests\Contract;

use PHPUnit\Framework\TestCase;
use Silviooosilva\CacheerPhp\Cacheer;
use Tests\Support\FakeClock;

abstract class StoreContractTestCase extends TestCase
{
    protected Cacheer $cache;

    protected FakeClock $clock;

    /**
     * @var array<string,mixed>
     */
    protected array $fixture;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clock = new FakeClock();
        $this->fixture = require dirname(__DIR__) . '/Fixtures/V5/characterization.php';
        $this->cache = $this->createCache($this->clock);
        $this->cache->flushCache();
    }

    protected function tearDown(): void
    {
        if (isset($this->cache)) {
            $this->cache->flushCache();
        }
        Cacheer::resetInstance();
        parent::tearDown();
    }

    abstract protected function createCache(FakeClock $clock): Cacheer;

    protected function advanceTime(float $seconds): void
    {
        $this->clock->advance($seconds);
    }

    public function testHitAndMissSemantics(): void
    {
        self::assertNull($this->cache->getCache('missing'));
        self::assertFalse($this->cache->isSuccess());

        self::assertTrue($this->cache->putCache('hit', $this->fixture['values']['scalar']));
        self::assertSame($this->fixture['values']['scalar'], $this->cache->getCache('hit'));
        self::assertTrue($this->cache->isSuccess());
    }

    public function testFalsyAndNullValuesRemainHits(): void
    {
        foreach (['false', 'empty_string', 'empty_array', 'null'] as $name) {
            $key = 'value-' . $name;
            self::assertTrue($this->cache->putCache($key, $this->fixture['values'][$name]));
            self::assertSame($this->fixture['values'][$name], $this->cache->getCache($key));
            self::assertTrue($this->cache->isSuccess(), sprintf('%s must remain distinguishable from a miss.', $name));
        }
    }

    public function testTtlExpirationUsesTheInjectedClock(): void
    {
        $this->cache->putCache('short-lived', 'value', '', 1);
        self::assertSame('value', $this->cache->getCache('short-lived'));

        $this->advanceTime(2);

        self::assertNull($this->cache->getCache('short-lived'));
        self::assertFalse($this->cache->isSuccess());
    }

    public function testDeleteAndClearSemantics(): void
    {
        $this->cache->putCache('one', 1);
        $this->cache->putCache('two', 2);
        self::assertTrue($this->cache->clearCache('one'));
        self::assertFalse($this->cache->has('one'));
        self::assertTrue($this->cache->has('two'));

        self::assertTrue($this->cache->flushCache());
        self::assertFalse($this->cache->has('two'));
    }

    public function testNamespacesAreIsolated(): void
    {
        $this->cache->putCache('shared', 'alpha', 'a');
        $this->cache->putCache('shared', 'beta', 'b');

        self::assertSame('alpha', $this->cache->getCache('shared', 'a'));
        self::assertSame('beta', $this->cache->getCache('shared', 'b'));
        self::assertNull($this->cache->getCache('shared'));
    }

    public function testMissingMutationReportsFailure(): void
    {
        self::assertFalse($this->cache->appendCache('missing', 'value'));
        self::assertFalse($this->cache->isSuccess());
    }

    public function testBatchCapability(): void
    {
        self::assertTrue($this->cache->putMany([
            ['cacheKey' => 'a', 'cacheData' => 1],
            ['cacheKey' => 'b', 'cacheData' => false],
            ['cacheKey' => 'c', 'cacheData' => null],
        ]));
        self::assertSame(['a' => 1, 'b' => false, 'c' => null], $this->cache->getMany(['a', 'b', 'c']));
    }

    public function testTagCapability(): void
    {
        $this->cache->putCache('tagged-a', 'a');
        $this->cache->putCache('tagged-b', 'b');
        self::assertTrue($this->cache->tag('group', 'tagged-a', 'tagged-b'));

        $this->cache->flushTag('group');

        self::assertFalse($this->cache->has('tagged-a'));
        self::assertFalse($this->cache->has('tagged-b'));
    }

    public function testLockCapability(): void
    {
        $first = $this->cache->lock('contract-lock', 10);
        $second = $this->cache->lock('contract-lock', 10);

        self::assertTrue($first->acquire());
        self::assertFalse($second->acquire());
        self::assertTrue($first->release());
        self::assertTrue($second->acquire());
        self::assertTrue($second->release());
    }

    public function testAtomicCapability(): void
    {
        $this->cache->putCache('counter', 0);
        self::assertTrue($this->cache->increment('counter', 3));
        self::assertTrue($this->cache->decrement('counter'));
        self::assertSame(2, $this->cache->getCache('counter'));
    }

    public function testTouchCapability(): void
    {
        $this->cache->putCache('touch', 'value', '', 3);
        $this->advanceTime(0.5);
        self::assertTrue($this->cache->renewCache('touch', 10));
        $this->advanceTime(4);
        self::assertSame('value', $this->cache->getCache('touch'));
    }

    public function testInspectionCapability(): void
    {
        $this->cache->putCache('visible', ['ok' => true], 'inspect');
        $entries = $this->cache->getAll('inspect');

        self::assertCount(1, $entries);
        self::assertContains(['ok' => true], array_values($entries));
    }
}
