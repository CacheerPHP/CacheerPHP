<?php

declare(strict_types=1);

namespace Tests\Kernel;

use DateInterval;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Silviooosilva\CacheerPhp\Exceptions\CacheMissException;
use Silviooosilva\CacheerPhp\Exceptions\InvalidKeyException;
use Silviooosilva\CacheerPhp\Exceptions\InvalidScopeException;
use Silviooosilva\CacheerPhp\Exceptions\InvalidTtlException;
use Silviooosilva\CacheerPhp\Kernel\CacheEntry;
use Silviooosilva\CacheerPhp\Kernel\Key;
use Silviooosilva\CacheerPhp\Kernel\Scope;
use Silviooosilva\CacheerPhp\Kernel\Ttl;
use Tests\Support\FakeClock;

final class ValueObjectsTest extends TestCase
{
    public function testKeysAreValidatedAndHaveCollisionFreeScopedIdentities(): void
    {
        $left = Key::named('bc')->within(Scope::named('a'));
        $right = Key::named('c')->within(Scope::named('ab'));

        self::assertNotSame($left->identity(), $right->identity());
        self::assertSame('a/bc', (string) $left);

        $this->expectException(InvalidKeyException::class);
        Key::named('');
    }

    public function testScopesAreImmutableAndNestPredictably(): void
    {
        $tenant = Scope::named('tenant');
        $users = $tenant->child('users');

        self::assertSame('tenant', (string) $tenant);
        self::assertSame('tenant/users', (string) $users);
        self::assertTrue($tenant->contains($users));
        self::assertFalse($users->contains($tenant));

        $this->expectException(InvalidScopeException::class);
        Scope::named('nested/scope');
    }

    public function testTtlNormalizesSupportedInputsWithoutReadingSystemTime(): void
    {
        self::assertSame(90, Ttl::from(90)->inSeconds());
        self::assertSame(600, Ttl::from('10 minutes')->inSeconds());
        self::assertSame(5400, Ttl::from(new DateInterval('PT1H30M'))->inSeconds());
        self::assertTrue(Ttl::from(null)->isForever());
        self::assertTrue(Ttl::from('forever')->isForever());
    }

    public function testAbsoluteTtlUsesTheInjectedClock(): void
    {
        $clock = new FakeClock(1_700_000_000);
        $expiration = new DateTimeImmutable('@1700000120');

        self::assertSame(120, Ttl::until($expiration, $clock)->inSeconds());
        self::assertSame(1_700_000_120, Ttl::seconds(120)->expiresAt($clock));
    }

    public function testZeroAndNegativeTtlAreRejected(): void
    {
        foreach ([0, -1, '0 seconds'] as $ttl) {
            try {
                Ttl::from($ttl);
                self::fail('Expected invalid TTL input to be rejected.');
            } catch (InvalidTtlException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testCacheEntryDistinguishesCachedNullFromAMiss(): void
    {
        $key = Key::named('nullable');
        $hit = CacheEntry::hit($key, null, 100, null);
        $miss = CacheEntry::miss($key);

        self::assertTrue($hit->isHit());
        self::assertNull($hit->value());
        self::assertTrue($miss->isMiss());
        self::assertSame('fallback', $miss->valueOr('fallback'));

        $this->expectException(CacheMissException::class);
        $miss->value();
    }
}
