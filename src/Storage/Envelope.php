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

        if (strlen($blob) <= strlen(self::MAGIC)) {
            throw CorruptedPayloadException::truncatedHeader();
        }

        $version = ord($blob[strlen(self::MAGIC)]);
        if ($version !== self::VERSION) {
            throw UnsupportedEnvelopeException::version($version);
        }

        // Walk the four header separators by offset, so the payload — which can
        // be large and may itself contain the separator byte — is copied once.
        $fields = [];
        $offset = strlen(self::MAGIC) + 1;
        for ($i = 0; $i < 4; $i++) {
            $end = strpos($blob, self::SEP, $offset);
            if ($end === false) {
                throw CorruptedPayloadException::truncatedHeader();
            }

            $fields[] = substr($blob, $offset, $end - $offset);
            $offset = $end + 1;
        }

        [$serializerId, $compressorId, $encrypterId, $keyId] = $fields;

        return new self($serializerId, $compressorId, $encrypterId, $keyId, substr($blob, $offset));
    }
}
