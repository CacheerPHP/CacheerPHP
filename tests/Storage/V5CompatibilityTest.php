<?php

declare(strict_types=1);

namespace Tests\Storage;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Silviooosilva\CacheerPhp\Config\PipelineConfig;
use Silviooosilva\CacheerPhp\Exceptions\UnsupportedEnvelopeException;
use Silviooosilva\CacheerPhp\Helpers\CacheerHelper;
use Silviooosilva\CacheerPhp\Storage\Compat\V5PayloadReader;
use Silviooosilva\CacheerPhp\Storage\Envelope;

/**
 * Fidelity checks against real v5 payloads: fixtures are produced by the v5
 * transform (CacheerHelper::prepareForStorage) still present in the tree, so
 * the v6 reader is verified against the exact bytes v5 wrote, not a guess.
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
            $v5Blob = (string) CacheerHelper::prepareForStorage($value, $compression, $key);

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
        $v5Blob = (string) CacheerHelper::prepareForStorage(['x' => 1], true, self::V5_KEY);

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
}
