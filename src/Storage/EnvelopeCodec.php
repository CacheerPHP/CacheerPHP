<?php

declare(strict_types=1);

namespace Silviooosilva\CacheerPhp\Storage;

use Silviooosilva\CacheerPhp\Contracts\Compressor;
use Silviooosilva\CacheerPhp\Contracts\Encrypter;
use Silviooosilva\CacheerPhp\Contracts\Serializer;
use Silviooosilva\CacheerPhp\Exceptions\UnsupportedEnvelopeException;
use Silviooosilva\CacheerPhp\Exceptions\ValueTooLargeException;
use Silviooosilva\CacheerPhp\Storage\Compat\V5PayloadReader;

/**
 * Turns a cache value into a versioned envelope and back.
 *
 * Encode runs serialize -> compress -> encrypt; decode reverses it, selecting
 * each stage by the id recorded in the envelope. A blob that is not a v6
 * envelope is handed to the configured v5 reader, or rejected as unsupported.
 * Failures are deterministic and typed; the codec never returns unauthenticated
 * or over-limit data.
 */
final class EnvelopeCodec
{
    public function __construct(
        private readonly Serializer $serializer,
        private readonly ?Compressor $compressor = null,
        private readonly ?Encrypter $encrypter = null,
        private readonly int $maxValueBytes = 0,
        private readonly ?V5PayloadReader $v5Reader = null,
    ) {
    }

    public function encode(mixed $value): string
    {
        $payload = $this->serializer->serialize($value);

        if ($this->maxValueBytes > 0 && strlen($payload) > $this->maxValueBytes) {
            throw ValueTooLargeException::onWrite(strlen($payload), $this->maxValueBytes);
        }

        $compressorId = Envelope::NONE;
        if ($this->compressor !== null) {
            $payload = $this->compressor->compress($payload);
            $compressorId = $this->compressor->id();
        }

        $encrypterId = Envelope::NONE;
        $keyId = '';
        if ($this->encrypter !== null) {
            $payload = $this->encrypter->encrypt($payload);
            $encrypterId = $this->encrypter->id();
            $keyId = $this->encrypter->activeKeyId();
        }

        return (new Envelope(
            $this->serializer->id(),
            $compressorId,
            $encrypterId,
            $keyId,
            $payload,
        ))->toString();
    }

    public function decode(string $blob): mixed
    {
        if (!Envelope::isEnvelope($blob)) {
            return $this->decodeLegacy($blob);
        }

        $envelope = Envelope::fromString($blob);
        $payload = $envelope->payload;

        if ($envelope->encrypterId !== Envelope::NONE) {
            $payload = $this->encrypterFor($envelope->encrypterId)->decrypt($payload, $envelope->keyId);
        }

        if ($envelope->compressorId !== Envelope::NONE) {
            $payload = $this->compressorFor($envelope->compressorId)->decompress($payload, $this->maxValueBytes);
        }

        if ($envelope->serializerId !== $this->serializer->id()) {
            throw UnsupportedEnvelopeException::stage('serializer', $envelope->serializerId);
        }

        return $this->serializer->unserialize($payload);
    }

    /**
     * True when the blob is not a v6 envelope and a v5 reader is configured to
     * decode it. Stores use this to detect values that should be rewritten in
     * the v6 format on read during a migration.
     */
    public function isLegacyBlob(string $blob): bool
    {
        return $this->v5Reader !== null && !Envelope::isEnvelope($blob);
    }

    private function decodeLegacy(string $blob): mixed
    {
        if ($this->v5Reader === null) {
            throw UnsupportedEnvelopeException::unrecognized();
        }

        return $this->v5Reader->read($blob);
    }

    private function encrypterFor(string $id): Encrypter
    {
        if ($this->encrypter === null || $this->encrypter->id() !== $id) {
            throw UnsupportedEnvelopeException::stage('encrypter', $id);
        }

        return $this->encrypter;
    }

    private function compressorFor(string $id): Compressor
    {
        if ($this->compressor === null || $this->compressor->id() !== $id) {
            throw UnsupportedEnvelopeException::stage('compressor', $id);
        }

        return $this->compressor;
    }
}
