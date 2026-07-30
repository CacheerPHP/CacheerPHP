<?php

declare(strict_types=1);

namespace Tests\Storage;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Silviooosilva\CacheerPhp\Config\PipelineConfig;
use Silviooosilva\CacheerPhp\Exceptions\UnsupportedEnvelopeException;
use Silviooosilva\CacheerPhp\Storage\Compat\V5PayloadReader;
use Silviooosilva\CacheerPhp\Storage\Envelope;

/**
 * Fidelity checks against v5 payloads. The fixtures are produced here by the
 * exact v5 transform (serialize -> gzcompress -> AES-256-CBC with a prepended
 * IV, base64-encoded), so the v6 reader is verified against the same bytes v5
 * wrote, not a guess.
 */
final class V5CompatibilityTest extends TestCase
{
    private const V5_KEY = 'v5-encryption-key';

    /**
     * @return array<string, array{bool, string|null}>
     */
    public static function v5Modes(): array
    {
        return [
            'compressed'             => [true, null],
            'encrypted'              => [false, self::V5_KEY],
            'compressed + encrypted' => [true, self::V5_KEY],
        ];
    }

    #[DataProvider('v5Modes')]
    public function testReadsTransformedV5PayloadsThroughTheCodec(bool $compression, ?string $key): void
    {
        $reader = new V5PayloadReader($compression, $key);
        $codec = PipelineConfig::default()->withV5Reader($reader)->codec();

        foreach ([['tenant' => 'acme'], 'scalar', 42, false, ['nested' => [1, 2, 3]]] as $value) {
            $v5Blob = self::v5Encode($value, $compression, $key);

            self::assertFalse(Envelope::isEnvelope($v5Blob), 'v5 payloads must not look like v6 envelopes.');
            self::assertEquals($value, $codec->decode($v5Blob));
        }
    }

    public function testReaderReturnsUntransformedV5ValuesVerbatim(): void
    {
        // With neither compression nor encryption, v5 stored the value as-is.
        $reader = new V5PayloadReader(false, null);

        self::assertSame('plain-string', $reader->read('plain-string'));
    }

    public function testLegacyPayloadIsUnsupportedWhenNoV5ReaderIsConfigured(): void
    {
        $v5Blob = self::v5Encode(['x' => 1], true, self::V5_KEY);

        $this->expectException(UnsupportedEnvelopeException::class);
        PipelineConfig::default()->codec()->decode($v5Blob);
    }

    public function testV6EnvelopesAreNeverMistakenForV5Payloads(): void
    {
        $reader = new V5PayloadReader(true, self::V5_KEY);
        $codec = PipelineConfig::default()->withGzip()->withV5Reader($reader)->codec();

        // A genuine v6 envelope must decode via the v6 path even with a reader present.
        self::assertSame('v6-native', $codec->decode($codec->encode('v6-native')));
    }

    /**
     * Reproduces the v5 storage transform: serialize -> optional gzip ->
     * optional AES-256-CBC (random 16-byte IV prepended, base64-encoded). This
     * is the exact inverse of {@see V5PayloadReader::read()}.
     */
    private static function v5Encode(mixed $value, bool $compression, ?string $key): string
    {
        $payload = serialize($value);

        if ($compression) {
            $payload = (string) gzcompress($payload);
        }

        if ($key !== null) {
            $iv = random_bytes(16);
            $cipher = (string) openssl_encrypt($payload, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
            $payload = base64_encode($iv . $cipher);
        }

        return $payload;
    }
}
