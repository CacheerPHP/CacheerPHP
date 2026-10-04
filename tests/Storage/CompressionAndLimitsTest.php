<?php

declare(strict_types=1);

namespace Tests\Storage;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Silviooosilva\CacheerPhp\Config\PipelineConfig;
use Silviooosilva\CacheerPhp\Exceptions\CorruptedPayloadException;
use Silviooosilva\CacheerPhp\Exceptions\ValueTooLargeException;
use Silviooosilva\CacheerPhp\Storage\Compression\GzipCompressor;
use Silviooosilva\CacheerPhp\Storage\Encryption\Keyring;
use Silviooosilva\CacheerPhp\Storage\EnvelopeCodec;
use Silviooosilva\CacheerPhp\Storage\Serializer\PhpSerializer;

final class CompressionAndLimitsTest extends TestCase
{
    public function testCompressionShrinksAndRestoresRepetitiveData(): void
    {
        $value = str_repeat('cacheer-php-', 4096);
        $codec = PipelineConfig::default()->withGzip()->codec();

        $blob = $codec->encode($value);

        self::assertLessThan(strlen($value), strlen($blob));
        self::assertSame($value, $codec->decode($blob));
    }

    public function testOversizedValuesAreRejectedOnWrite(): void
    {
        $codec = PipelineConfig::default()->withMaxValueBytes(64)->codec();

        try {
            $codec->encode(str_repeat('x', 512));
            self::fail('Expected an oversized value to be rejected on write.');
        } catch (ValueTooLargeException) {
            self::addToAssertionCount(1);
        }

        self::assertIsString($codec->encode('small'));
    }

    public function testDecompressionStopsAtTheConfiguredCeiling(): void
    {
        // Written without a limit, so the large value compresses fine...
        $blob = PipelineConfig::default()->withGzip()->codec()->encode(str_repeat('q', 200_000));

        // ...but a reader with a ceiling refuses to inflate past it.
        $bounded = PipelineConfig::default()->withGzip()->withMaxValueBytes(10_000)->codec();

        $this->expectException(ValueTooLargeException::class);
        $bounded->decode($blob);
    }

    /**
     * @return array<string, array{\Closure(PipelineConfig): PipelineConfig}>
     */
    public static function pipelines(): array
    {
        $keyring = static fn (): Keyring => new Keyring(['k1' => str_repeat("\x11", 32)], 'k1');

        return [
            'plain'      => [static fn (PipelineConfig $p): PipelineConfig => $p],
            'compressed' => [static fn (PipelineConfig $p): PipelineConfig => $p->withGzip()],
            'encrypted'  => [static fn (PipelineConfig $p): PipelineConfig => $p->withKeyring($keyring())],
            'both'       => [static fn (PipelineConfig $p): PipelineConfig => $p->withGzip()->withKeyring($keyring())],
        ];
    }

    /**
     * @param \Closure(PipelineConfig): PipelineConfig $stages
     */
    #[DataProvider('pipelines')]
    public function testTheReadLimitHoldsOnEveryPipeline(\Closure $stages): void
    {
        // Written by an unlimited writer (another app, an older config, or
        // anyone with backend access), read by a pipeline with a ceiling.
        $blob = $stages(PipelineConfig::default())->codec()->encode(str_repeat('x', 5_000));
        $bounded = $stages(PipelineConfig::default()->withMaxValueBytes(1_000))->codec();

        $this->expectException(ValueTooLargeException::class);
        $bounded->decode($blob);
    }

    #[DataProvider('pipelines')]
    public function testAValueAtTheLimitStillReads(\Closure $stages): void
    {
        $codec = $stages(PipelineConfig::default()->withMaxValueBytes(1_000))->codec();
        $value = str_repeat('x', 991);
        self::assertSame(1_000, strlen(serialize($value)), 'The serialized value sits exactly at the limit.');

        self::assertSame($value, $codec->decode($codec->encode($value)));
    }

    public function testANegativeLimitIsRejected(): void
    {
        foreach ([
            'PipelineConfig' => static fn (): mixed => PipelineConfig::default()->withMaxValueBytes(-1),
            'EnvelopeCodec'  => static fn (): mixed => new EnvelopeCodec(new PhpSerializer(), maxValueBytes: -1),
        ] as $where => $attempt) {
            try {
                $attempt();
                self::fail(sprintf('%s must reject a negative value limit.', $where));
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testMalformedCompressedStreamIsReportedAsCorrupt(): void
    {
        $compressor = new GzipCompressor();

        $this->expectException(CorruptedPayloadException::class);
        $compressor->decompress('this is not a zlib stream');
    }

    public function testCompressorRoundTripsBinaryData(): void
    {
        $compressor = new GzipCompressor();
        $binary = random_bytes(2048);

        self::assertSame($binary, $compressor->decompress($compressor->compress($binary)));
    }
}
