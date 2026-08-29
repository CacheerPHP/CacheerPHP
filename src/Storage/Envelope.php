<?php

declare(strict_types=1);

namespace Silviooosilva\CacheerPhp\Storage;

use Silviooosilva\CacheerPhp\Exceptions\CorruptedPayloadException;
use Silviooosilva\CacheerPhp\Exceptions\UnsupportedEnvelopeException;

/**
 * The versioned, self-describing wire format for a stored value.
 *
 * Layout: MAGIC . versionByte . serializerId . SEP . compressorId . SEP .
 * encrypterId . SEP . keyId . SEP . payload
 *
 * The leading NUL in MAGIC makes v6 envelopes impossible to confuse with any
 * v5 payload: PHP serialize() output, a zlib stream, and base64 all begin with
 * a non-NUL byte. The payload is the final field, so it may contain any bytes
 * (including SEP) without ambiguity.
 */
final readonly class Envelope
{
    public const VERSION = 1;

    public const NONE = 'none';

    private const MAGIC = "\x00CFE";

    private const SEP = "\x1E";

    /**
     * @param string $serializerId
     * @param string $compressorId
     * @param string $encrypterId
     * @param string $keyId
     * @param string $payload
     */
    public function __construct(
        public string $serializerId,
        public string $compressorId,
        public string $encrypterId,
        public string $keyId,
        public string $payload,
    ) {
    }

    /**
     * Whether a raw blob is a v6 envelope (as opposed to a legacy v5 payload).
     *
     * @param string $blob
     * @return bool
     */
    public static function isEnvelope(string $blob): bool
    {
        return str_starts_with($blob, self::MAGIC);
    }

    /**
     * @return string
     */
    public function toString(): string
    {
        return self::MAGIC
            . chr(self::VERSION)
            . $this->serializerId . self::SEP
            . $this->compressorId . self::SEP
            . $this->encrypterId . self::SEP
            . $this->keyId . self::SEP
            . $this->payload;
    }

    /**
     * @param string $blob
     * @return Envelope
     */
    public static function fromString(string $blob): self
    {
        if (!self::isEnvelope($blob)) {
            throw UnsupportedEnvelopeException::unrecognized();
        }

        $version = ord($blob[strlen(self::MAGIC)]);
        if ($version !== self::VERSION) {
            throw UnsupportedEnvelopeException::version($version);
        }

        $header = substr($blob, strlen(self::MAGIC) + 1);
        $fields = explode(self::SEP, $header, 5);
        if (count($fields) !== 5) {
            throw CorruptedPayloadException::truncatedHeader();
        }

        [$serializerId, $compressorId, $encrypterId, $keyId, $payload] = $fields;

        return new self($serializerId, $compressorId, $encrypterId, $keyId, $payload);
    }
}
