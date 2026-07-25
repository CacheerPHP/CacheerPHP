<?php

declare(strict_types=1);

namespace Tests\Storage;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Silviooosilva\CacheerPhp\Config\PipelineConfig;
use Silviooosilva\CacheerPhp\Exceptions\CorruptedPayloadException;
use Silviooosilva\CacheerPhp\Exceptions\UnsupportedEnvelopeException;
use Silviooosilva\CacheerPhp\Storage\Encryption\Keyring;
use Silviooosilva\CacheerPhp\Storage\Encryption\OpenSslGcmEncrypter;

final class EncryptionTest extends TestCase
{
    private function keyring(string $activeId = 'k1'): Keyring
    {
        return new Keyring([
            'k1' => str_repeat("\x11", 32),
            'k2' => str_repeat("\x22", 32),
        ], $activeId);
    }

    public function testEncryptedValuesRoundTripAndAreNotStoredInCleartext(): void
    {
        $codec = PipelineConfig::default()->withKeyring($this->keyring())->codec();

        $blob = $codec->encode('top-secret');

        self::assertStringNotContainsString('top-secret', $blob);
        self::assertSame('top-secret', $codec->decode($blob));
    }

    public function testTamperedCiphertextIsRejectedByAuthentication(): void
    {
        $codec = PipelineConfig::default()->withKeyring($this->keyring())->codec();
        $blob = $codec->encode(['balance' => 100]);

        $flipped = $blob;
        $last = strlen($flipped) - 1;
        $flipped[$last] = $flipped[$last] === "\x00" ? "\x01" : "\x00";

        $this->expectException(CorruptedPayloadException::class);
        $codec->decode($flipped);
    }

    public function testAWrongKeyCannotDecryptAndNeverReturnsData(): void
    {
        $written = PipelineConfig::default()->withKeyring($this->keyring('k1'))->codec()->encode('secret');

        $otherRing = new Keyring(['k1' => str_repeat("\x99", 32)], 'k1');
        $reader = PipelineConfig::default()->withKeyring($otherRing)->codec();

        $this->expectException(CorruptedPayloadException::class);
        $reader->decode($written);
    }

    public function testRotatedKeyStillReadsEntriesWrittenWithTheOldActiveKey(): void
    {
        // Written while k1 was active.
        $legacy = PipelineConfig::default()->withKeyring($this->keyring('k1'))->codec()->encode('legacy');

        // k2 is now active, but k1 remains in the ring.
        $rotated = PipelineConfig::default()->withKeyring($this->keyring('k2'))->codec();

        self::assertSame('legacy', $rotated->decode($legacy));
        self::assertSame('k2', (new OpenSslGcmEncrypter($this->keyring('k2')))->activeKeyId());
    }

    public function testDecodingAnUnknownKeyIdIsUnsupportedRatherThanCorrupt(): void
    {
        $written = PipelineConfig::default()->withKeyring($this->keyring('k2'))->codec()->encode('x');

        // A ring that lacks k2 cannot even look up the key.
        $ringWithoutK2 = new Keyring(['k1' => str_repeat("\x11", 32)], 'k1');

        $this->expectException(UnsupportedEnvelopeException::class);
        PipelineConfig::default()->withKeyring($ringWithoutK2)->codec()->decode($written);
    }

    public function testKeyringRejectsWrongLengthKeysAndAbsentActiveKey(): void
    {
        try {
            new Keyring(['k1' => 'too-short'], 'k1');
            self::fail('Expected a wrong-length key to be rejected.');
        } catch (InvalidArgumentException) {
            self::addToAssertionCount(1);
        }

        $this->expectException(InvalidArgumentException::class);
        new Keyring(['k1' => str_repeat('a', 32)], 'missing');
    }

    public function testEncryptionComposesWithCompression(): void
    {
        $codec = PipelineConfig::default()->withGzip()->withKeyring($this->keyring())->codec();
        $value = ['blob' => str_repeat('compress-me-', 1000)];

        self::assertSame($value, $codec->decode($codec->encode($value)));
    }
}
