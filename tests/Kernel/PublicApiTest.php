<?php

declare(strict_types=1);

namespace Tests\Kernel;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use Silviooosilva\CacheerPhp\Cacheer;
use Silviooosilva\CacheerPhp\Config\CachePolicy;
use Silviooosilva\CacheerPhp\Contracts\Cache;
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
     * The instance surface promised by the docs — one object carries all of it,
     * including the capability verbs that used to require the raw store.
     *
     * @return array<string, array{string}>
     */
    public static function cacheOperations(): array
    {
        return self::named([
            // core
            'entry', 'get', 'set', 'delete', 'clear', 'has', 'missing',
            'many', 'setMany', 'deleteMany',
            // v5 verbs kept
            'forever', 'add', 'pull', 'rememberForever',
            // compute
            'remember', 'flexible',
            // capabilities, reached on the cache with the scope applied
            'supports', 'increment', 'decrement', 'touch',
            'tag', 'flushTag', 'lock', 'entries', 'prune',
            // views
            'withPolicy', 'scope', 'in', 'boundScope', 'formatted', 'stats',
        ]);
    }

    /**
     * Whatever Cacheer promises, the formatted view must forward — reading
     * through it must not silently cost you part of the API.
     */
    public function testFormattedViewForwardsTheWholeSurface(): void
    {
        $formatted = Cacheer::inMemory()->formatted();

        foreach (array_keys(self::cacheOperations()) as $method) {
            self::assertTrue(
                method_exists($formatted, $method),
                "FormattedCacheer lost Cache::{$method}().",
            );
        }
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

    /**
     * Scoping used to return a different, smaller type. It now returns the same
     * one, so a scoped cache cannot silently lack part of the surface.
     */
    public function testScopingAndPolicyBindingPreserveTheWholeSurface(): void
    {
        $cache = Cacheer::inMemory();
        $derived = [
            'scoped'    => $cache->scope('tenant'),
            'aliased'   => $cache->in('tenant'),
            'policied'  => $cache->withPolicy(CachePolicy::defaults()),
            'both'      => $cache->in('tenant')->withPolicy(CachePolicy::defaults()),
            'reordered' => $cache->withPolicy(CachePolicy::defaults())->in('tenant'),
            'nested'    => $cache->scope('a')->scope('b'),
        ];

        $surface = array_map(
            static fn (\ReflectionMethod $m): string => $m->getName(),
            (new ReflectionClass(Cache::class))->getMethods(),
        );

        foreach ($derived as $label => $instance) {
            self::assertInstanceOf(Cache::class, $instance, "{$label} must still be a Cache.");

            foreach ($surface as $method) {
                self::assertTrue(
                    method_exists($instance, $method),
                    "{$label} lost Cache::{$method}().",
                );
            }
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
