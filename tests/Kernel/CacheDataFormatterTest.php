<?php

declare(strict_types=1);

namespace Tests\Kernel;

use PHPUnit\Framework\TestCase;
use Silviooosilva\CacheerPhp\Cacheer;
use Silviooosilva\CacheerPhp\Support\CacheDataFormatter;
use Silviooosilva\CacheerPhp\Support\FormattedCacheer;
use Tests\Support\FakeClock;

final class CacheDataFormatterTest extends TestCase
{
    public function testFormatsAValueFourWays(): void
    {
        $formatter = new CacheDataFormatter(['id' => 42, 'name' => 'Ada']);

        self::assertJsonStringEqualsJsonString('{"id":42,"name":"Ada"}', $formatter->toJson());
        self::assertSame(['id' => 42, 'name' => 'Ada'], $formatter->toArray());
        self::assertEquals((object) ['id' => 42, 'name' => 'Ada'], $formatter->toObject());
        self::assertSame(['id' => 42, 'name' => 'Ada'], $formatter->value());
    }

    public function testToStringSuitsScalars(): void
    {
        self::assertSame('cacheer', (new CacheDataFormatter('cacheer'))->toString());
        self::assertSame('42', (new CacheDataFormatter(42))->toString());
    }

    public function testFormattedViewReturnsAFormatterFromGet(): void
    {
        $cache = new Cacheer(new \Silviooosilva\CacheerPhp\Stores\ArrayStore(new FakeClock()));
        $cache->set('user:1', ['id' => 1, 'name' => 'Ada']);

        $formatted = $cache->formatted();
        self::assertInstanceOf(FormattedCacheer::class, $formatted);

        // The exact "write less" syntax.
        self::assertJsonStringEqualsJsonString(
            '{"id":1,"name":"Ada"}',
            $formatted->get('user:1')->toJson(),
        );
        self::assertSame(['id' => 1, 'name' => 'Ada'], $formatted->get('user:1')->toArray());
    }

    public function testFormattedViewProxiesWritesAndKeepsBaseGetRaw(): void
    {
        $cache = Cacheer::inMemory();
        $formatted = $cache->formatted();

        $formatted->set('flag', false);           // write through the view
        self::assertTrue($formatted->has('flag'));

        // Base get() stays raw — false is returned as-is, not wrapped.
        self::assertFalse($cache->get('flag'));
        // The view wraps it.
        self::assertFalse($formatted->get('flag')->value());

        self::assertTrue($formatted->delete('flag'));
        self::assertFalse($cache->has('flag'));
    }

    public function testFormattedViewRemembersAndScopes(): void
    {
        $formatted = Cacheer::inMemory()->formatted();

        $json = $formatted->remember('report', 60, fn () => ['rows' => 3])->toJson();
        self::assertJsonStringEqualsJsonString('{"rows":3}', $json);

        $formatted->scope('billing')->set('n', 7);
        self::assertSame(7, $formatted->scope('billing')->get('n')->value());
        self::assertNull($formatted->raw()->get('n')); // root scope untouched
    }

    public function testMissReturnsAFormatterWrappingTheDefault(): void
    {
        $formatted = Cacheer::inMemory()->formatted();

        self::assertNull($formatted->get('absent')->value());
        self::assertSame('fallback', $formatted->get('absent', 'fallback')->value());
    }
}
