<?php

declare(strict_types=1);

namespace Tests\Storage;

use PHPUnit\Framework\TestCase;
use Silviooosilva\CacheerPhp\Kernel\Key;
use Silviooosilva\CacheerPhp\Kernel\Scope;
use Silviooosilva\CacheerPhp\Storage\KeyEncoder\HashingKeyEncoder;

final class KeyEncoderTest extends TestCase
{
    public function testEncodingIsDeterministicAndBackendSafe(): void
    {
        $encoder = new HashingKeyEncoder();
        $key = Key::named('user:42')->within(Scope::named('tenant'));

        $encoded = $encoder->encode($key);

        self::assertSame($encoded, $encoder->encode($key));
        self::assertSame(1, preg_match('/^[A-Za-z0-9_.-]+$/', $encoded));
    }

    public function testDistinctKeysAndScopesDoNotCollide(): void
    {
        $encoder = new HashingKeyEncoder();

        $a = $encoder->encode(Key::named('bc')->within(Scope::named('a')));
        $b = $encoder->encode(Key::named('c')->within(Scope::named('ab')));
        $rootValue = $encoder->encode(Key::named('value'));
        $scopedValue = $encoder->encode(Key::named('value')->within(Scope::named('scope')));

        self::assertNotSame($a, $b);
        self::assertNotSame($rootValue, $scopedValue);
    }

    public function testNamespacePrefixScopesKeysWithinASharedBackend(): void
    {
        $encoder = new HashingKeyEncoder('app1');
        $encoded = $encoder->encode(Key::named('k'));

        self::assertStringStartsWith('app1:', $encoded);
    }
}
