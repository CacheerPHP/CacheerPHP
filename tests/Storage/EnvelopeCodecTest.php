<?php

declare(strict_types=1);

namespace Tests\Storage;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Silviooosilva\CacheerPhp\Config\PipelineConfig;
use Silviooosilva\CacheerPhp\Exceptions\CorruptedPayloadException;
use Silviooosilva\CacheerPhp\Exceptions\UnsupportedEnvelopeException;
use Silviooosilva\CacheerPhp\Storage\Envelope;

final class EnvelopeCodecTest extends TestCase
{
    /**
     * @return array<string, array{mixed}>
     */
    public static function values(): array
    {
        return [
            'scalar'       => ['cacheer'],
            'integer'      => [42],
            'float'        => [3.14],
            'false'        => [false],
            'empty string' => [''],
            'empty array'  => [[]],
            'null'         => [null],
            'nested array' => [['framework' => 'agnostic', 'nested' => [1, 2, 3]]],
            'object'       => [(object) ['type' => 'fixture', 'version' => 6]],
        ];
    }

    #[DataProvider('values')]
    public function testPhpPipelineRoundTripsEveryValueType(mixed $value): void
    {
        $codec = PipelineConfig::default()->codec();

        $blob = $codec->encode($value);

        self::assertTrue(Envelope::isEnvelope($blob));
        self::assertEquals($value, $codec->decode($blob));
    }

    public function testJsonSerializerRoundTripsArrayShapedData(): void
    {
        $codec = PipelineConfig::default()->withJsonSerializer()->codec();
        $value = ['name' => 'Ada', 'roles' => ['admin', 'user'], 'active' => true];

        self::assertSame($value, $codec->decode($codec->encode($value)));
    }

    public function testEnvelopeRecordsTheStageIdsThatProducedIt(): void
    {
        $blob = PipelineConfig::default()->withGzip()->codec()->encode('payload');
        $envelope = Envelope::fromString($blob);

        self::assertSame('php', $envelope->serializerId);
        self::assertSame('gzip', $envelope->compressorId);
        self::assertSame(Envelope::NONE, $envelope->encrypterId);
    }

    public function testDecodeRejectsAnUnknownEnvelopeVersion(): void
    {
        $blob = PipelineConfig::default()->codec()->encode('value');
        $tampered = substr_replace($blob, chr(Envelope::VERSION + 1), 4, 1);

        $this->expectException(UnsupportedEnvelopeException::class);
        PipelineConfig::default()->codec()->decode($tampered);
    }

    public function testDecodeRejectsATruncatedEnvelopeHeader(): void
    {
        // A v6 magic with an incomplete header (fewer than the required fields).
        $truncated = "\x00CFE" . chr(Envelope::VERSION) . 'php';

        $this->expectException(CorruptedPayloadException::class);
        PipelineConfig::default()->codec()->decode($truncated);
    }

    public function testDecodeRejectsAnEnvelopeFromADifferentSerializer(): void
    {
        $blob = PipelineConfig::default()->withJsonSerializer()->codec()->encode(['a' => 1]);

        $this->expectException(UnsupportedEnvelopeException::class);
        PipelineConfig::default()->codec()->decode($blob);
    }

    public function testDecodeRejectsAnEnvelopeNeedingAnAbsentCompressor(): void
    {
        $blob = PipelineConfig::default()->withGzip()->codec()->encode('value');

        $this->expectException(UnsupportedEnvelopeException::class);
        PipelineConfig::default()->codec()->decode($blob);
    }
}
