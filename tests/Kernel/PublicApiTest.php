<?php

declare(strict_types=1);

namespace Tests\Kernel;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Silviooosilva\CacheerPhp\Cacheer;
use Silviooosilva\CacheerPhp\Kernel\ScopedCacheer;
use Silviooosilva\CacheerPhp\Psr\Psr16Cache;
use Silviooosilva\CacheerPhp\Psr\Psr6Pool;

/**
 * Validates the documented v6 public API against the real class signatures, so
 * the README, capability matrix, and migration guide cannot drift from the code
 * they describe. This is the "validate API documentation from real method
 * signatures" gate rather than a hand-maintained list.
 */
final class PublicApiTest extends TestCase
{
    /**
     * The instance surface promised by the docs. Kept deliberately small.
     *
     * @return array<string, array{string}>
     */
    public static function cacheOperations(): array
    {
        return self::named([
            'entry', 'get', 'set', 'delete', 'clear', 'has',
            'remember', 'flexible', 'many', 'setMany', 'deleteMany',
            'withPolicy', 'scope', 'formatted',
        ]);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function cacheConstructors(): array
    {
        return self::named([
            'inMemory', 'file', 'database', 'redis',
            'tiered', 'resilient', 'instrumented',
        ]);
    }

    #[DataProvider('cacheOperations')]
    public function testCacheExposesDocumentedOperation(string $method): void
    {
        self::assertPublicInstanceMethod(Cacheer::class, $method);
    }

    #[DataProvider('cacheConstructors')]
    public function testCacheExposesDocumentedNamedConstructor(string $method): void
    {
        $reflection = new ReflectionMethod(Cacheer::class, $method);
        self::assertTrue($reflection->isPublic(), "Cacheer::{$method}() must be public.");
        self::assertTrue($reflection->isStatic(), "Cacheer::{$method}() must be a static named constructor.");
        self::assertContains(
            $reflection->getReturnType()?->__toString(),
            ['self', Cacheer::class],
            "Cacheer::{$method}() must return a Cache.",
        );
    }

    public function testScopedCacheMirrorsTheCoreReadWriteSurface(): void
    {
        foreach (['get', 'set', 'delete', 'has', 'remember', 'scope'] as $method) {
            self::assertPublicInstanceMethod(ScopedCacheer::class, $method);
        }
    }

    public function testPsrAdaptersImplementTheirInterfaces(): void
    {
        self::assertTrue(is_a(Psr16Cache::class, \Psr\SimpleCache\CacheInterface::class, true));
        self::assertTrue(is_a(Psr6Pool::class, \Psr\Cache\CacheItemPoolInterface::class, true));
    }

    private static function assertPublicInstanceMethod(string $class, string $method): void
    {
        $reflection = new ReflectionMethod($class, $method);
        self::assertTrue($reflection->isPublic(), "{$class}::{$method}() must be public.");
        self::assertFalse($reflection->isStatic(), "{$class}::{$method}() must be an instance method.");
    }

    /**
     * @param list<string> $methods
     * @return array<string, array{string}>
     */
    private static function named(array $methods): array
    {
        $cases = [];
        foreach ($methods as $method) {
            $cases[$method] = [$method];
        }

        return $cases;
    }
}
