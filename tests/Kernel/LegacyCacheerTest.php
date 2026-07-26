<?php

declare(strict_types=1);

namespace Tests\Kernel;

use PHPUnit\Framework\TestCase;
use Silviooosilva\CacheerPhp\Compat\LegacyCacheer;
use Silviooosilva\CacheerPhp\Stores\ArrayStore;
use Tests\Support\FakeClock;

final class LegacyCacheerTest extends TestCase
{
    private FakeClock $clock;

    private LegacyCacheer $legacy;

    protected function setUp(): void
    {
        $this->clock = new FakeClock();
        $this->legacy = new LegacyCacheer(new ArrayStore($this->clock), $this->clock);
    }

    public function testPutAndGetRoundTripReportsSuccess(): void
    {
        self::assertTrue($this->legacy->putCache('user:1', ['name' => 'Ada']));
        self::assertTrue($this->legacy->isSuccess());

        self::assertSame(['name' => 'Ada'], $this->legacy->getCache('user:1'));
        self::assertTrue($this->legacy->isSuccess());
        self::assertSame('Cache retrieved successfully.', $this->legacy->getMessage());
    }

    public function testGetMissReturnsDefaultAndFlagsFailure(): void
    {
        self::assertSame('fallback', $this->legacy->getCache('absent', '', 'fallback'));
        self::assertFalse($this->legacy->isSuccess());
    }

    public function testNamespaceTranslatesToScope(): void
    {
        $this->legacy->putCache('key', 'scoped', 'reports');

        self::assertSame('scoped', $this->legacy->getCache('key', 'reports'));
        self::assertNull($this->legacy->getCache('key'));
        self::assertFalse($this->legacy->isSuccess());
    }

    public function testForeverIgnoresExpiry(): void
    {
        $this->legacy->forever('permanent', 42);
        $this->clock->advance(86_400 * 3650);

        self::assertSame(42, $this->legacy->getCache('permanent'));
    }

    public function testClearAndFlush(): void
    {
        $this->legacy->putCache('a', 1);
        $this->legacy->putCache('b', 2);

        self::assertTrue($this->legacy->clearCache('a'));
        self::assertNull($this->legacy->getCache('a'));
        self::assertSame(2, $this->legacy->getCache('b'));

        self::assertTrue($this->legacy->flushCache());
        self::assertNull($this->legacy->getCache('b'));
    }

    public function testPullReadsThenDeletes(): void
    {
        $this->legacy->putCache('once', 'value');

        self::assertSame('value', $this->legacy->getAndForget('once'));
        self::assertNull($this->legacy->getCache('once'));
        self::assertNull($this->legacy->pull('once'));
    }

    public function testHasAndMissing(): void
    {
        $this->legacy->putCache('present', true);

        self::assertTrue($this->legacy->has('present'));
        self::assertFalse($this->legacy->missing('present'));
        self::assertTrue($this->legacy->missing('gone'));
    }

    public function testRenewExtendsTtl(): void
    {
        $this->legacy->putCache('session', 'live', '', 10);
        $this->clock->advance(8);

        self::assertTrue($this->legacy->renewCache('session', 60));
        $this->clock->advance(30);

        self::assertSame('live', $this->legacy->getCache('session'));
    }

    public function testRenewMissingFails(): void
    {
        self::assertFalse($this->legacy->renewCache('nope', 60));
        self::assertFalse($this->legacy->isSuccess());
    }

    public function testIncrementAndDecrementReturnTheNewValue(): void
    {
        self::assertSame(1, $this->legacy->increment('hits'));
        self::assertSame(5, $this->legacy->increment('hits', 4));
        self::assertSame(3, $this->legacy->decrement('hits', 2));
        self::assertSame(3, $this->legacy->getCache('hits'));
    }

    public function testRememberComputesOnceThenServesCache(): void
    {
        $calls = 0;
        $compute = function () use (&$calls): string {
            $calls++;

            return 'computed';
        };

        self::assertSame('computed', $this->legacy->remember('report', 60, $compute));
        self::assertSame('computed', $this->legacy->remember('report', 60, $compute));
        self::assertSame(1, $calls);
    }

    public function testTagAndFlushTag(): void
    {
        $this->legacy->putCache('p1', 'a');
        $this->legacy->putCache('p2', 'b');
        self::assertTrue($this->legacy->tag('products', 'p1', 'p2'));

        self::assertTrue($this->legacy->flushTag('products'));
        self::assertNull($this->legacy->getCache('p1'));
        self::assertNull($this->legacy->getCache('p2'));
    }

    public function testAppendMergesArraysAndConcatenatesStrings(): void
    {
        $this->legacy->putCache('list', ['a']);
        $this->legacy->appendCache('list', ['b']);
        self::assertSame(['a', 'b'], $this->legacy->getCache('list'));

        $this->legacy->putCache('text', 'foo');
        $this->legacy->appendCache('text', 'bar');
        self::assertSame('foobar', $this->legacy->getCache('text'));
    }

    public function testDeprecationsAreSilentByDefault(): void
    {
        $emitted = [];
        set_error_handler(function (int $errno, string $message) use (&$emitted): bool {
            $emitted[] = $message;

            return true;
        }, E_USER_DEPRECATED);

        try {
            $this->legacy->putCache('quiet', 1);
        } finally {
            restore_error_handler();
        }

        self::assertSame([], $emitted);
    }

    public function testDeprecationsAreEmittedWhenEnabled(): void
    {
        $loud = new LegacyCacheer(new ArrayStore($this->clock), $this->clock, emitDeprecations: true);

        $emitted = [];
        set_error_handler(function (int $errno, string $message) use (&$emitted): bool {
            $emitted[] = $message;

            return true;
        }, E_USER_DEPRECATED);

        try {
            $loud->putCache('loud', 1);
        } finally {
            restore_error_handler();
        }

        self::assertCount(1, $emitted);
        self::assertStringContainsString('putCache() is deprecated', $emitted[0]);
        self::assertStringContainsString('Cache::set()', $emitted[0]);
    }
}
