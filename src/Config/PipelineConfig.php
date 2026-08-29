<?php

declare(strict_types=1);

namespace Silviooosilva\CacheerPhp\Config;

use Silviooosilva\CacheerPhp\Contracts\Compressor;
use Silviooosilva\CacheerPhp\Contracts\Encrypter;
use Silviooosilva\CacheerPhp\Contracts\Serializer;
use Silviooosilva\CacheerPhp\Storage\Compat\V5PayloadReader;
use Silviooosilva\CacheerPhp\Storage\Compression\GzipCompressor;
use Silviooosilva\CacheerPhp\Storage\Encryption\Keyring;
use Silviooosilva\CacheerPhp\Storage\Encryption\OpenSslGcmEncrypter;
use Silviooosilva\CacheerPhp\Storage\EnvelopeCodec;
use Silviooosilva\CacheerPhp\Storage\Serializer\JsonSerializer;
use Silviooosilva\CacheerPhp\Storage\Serializer\PhpSerializer;

/**
 * Immutable, typed description of a value-storage pipeline.
 *
 * Persistent stores (Milestone 4) are configured with one of these and call
 * codec() to obtain a ready EnvelopeCodec. Every with*() method returns a new
 * instance, so a base configuration can be shared and specialized safely.
 */
final readonly class PipelineConfig
{
    /**
     * @param Serializer $serializer
     * @param ?Compressor $compressor
     * @param ?Encrypter $encrypter
     * @param int $maxValueBytes
     * @param ?V5PayloadReader $v5Reader
     */
    private function __construct(
        private Serializer $serializer,
        private ?Compressor $compressor,
        private ?Encrypter $encrypter,
        private int $maxValueBytes,
        private ?V5PayloadReader $v5Reader,
    ) {
    }

    /**
     * The safe default: native PHP serialization, no compression or encryption.
     *
     * @return PipelineConfig
     */
    public static function default(): self
    {
        return new self(new PhpSerializer(), null, null, 0, null);
    }

    /**
     * @param Serializer $serializer
     * @return PipelineConfig
     */
    public function withSerializer(Serializer $serializer): self
    {
        return new self($serializer, $this->compressor, $this->encrypter, $this->maxValueBytes, $this->v5Reader);
    }

    /**
     * @return PipelineConfig
     */
    public function withJsonSerializer(): self
    {
        return $this->withSerializer(new JsonSerializer());
    }

    /**
     * @param Compressor $compressor
     * @return PipelineConfig
     */
    public function withCompressor(Compressor $compressor): self
    {
        return new self($this->serializer, $compressor, $this->encrypter, $this->maxValueBytes, $this->v5Reader);
    }

    /**
     * @param int $level
     * @return PipelineConfig
     */
    public function withGzip(int $level = 6): self
    {
        return $this->withCompressor(new GzipCompressor($level));
    }

    /**
     * @param Encrypter $encrypter
     * @return PipelineConfig
     */
    public function withEncrypter(Encrypter $encrypter): self
    {
        return new self($this->serializer, $this->compressor, $encrypter, $this->maxValueBytes, $this->v5Reader);
    }

    /**
     * @param Keyring $keyring
     * @return PipelineConfig
     */
    public function withKeyring(Keyring $keyring): self
    {
        return $this->withEncrypter(new OpenSslGcmEncrypter($keyring));
    }

    /**
     * @param int $maxValueBytes
     * @return PipelineConfig
     */
    public function withMaxValueBytes(int $maxValueBytes): self
    {
        return new self($this->serializer, $this->compressor, $this->encrypter, $maxValueBytes, $this->v5Reader);
    }

    /**
     * @param V5PayloadReader $reader
     * @return PipelineConfig
     */
    public function withV5Reader(V5PayloadReader $reader): self
    {
        return new self($this->serializer, $this->compressor, $this->encrypter, $this->maxValueBytes, $reader);
    }

    /**
     * @return EnvelopeCodec
     */
    public function codec(): EnvelopeCodec
    {
        return new EnvelopeCodec(
            $this->serializer,
            $this->compressor,
            $this->encrypter,
            $this->maxValueBytes,
            $this->v5Reader,
        );
    }
}
