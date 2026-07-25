<?php

declare(strict_types=1);

namespace Tests\Storage;

use PHPUnit\Framework\TestCase;
use Silviooosilva\CacheerPhp\Config\PipelineConfig;
use Silviooosilva\CacheerPhp\Exceptions\CorruptedPayloadException;
use Silviooosilva\CacheerPhp\Exceptions\ValueTooLargeException;
use Silviooosilva\CacheerPhp\Storage\Compression\GzipCompressor;

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
