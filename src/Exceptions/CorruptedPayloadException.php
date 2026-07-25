<?php

declare(strict_types=1);

namespace Silviooosilva\CacheerPhp\Exceptions;

/**
 * Raised when a stored payload cannot be trusted: a truncated envelope, a
 * malformed compressed stream, or an authentication failure (wrong key or
 * tampered ciphertext). The pipeline never returns unauthenticated data.
 */
final class CorruptedPayloadException extends \RuntimeException implements CacheException
{
    public static function truncatedHeader(): self
    {
        return new self('Cache payload is truncated: the value envelope header is incomplete.');
    }

    public static function truncatedCiphertext(): self
    {
        return new self('Cache payload is truncated: the ciphertext is shorter than the nonce and tag.');
    }

    public static function authenticationFailed(): self
    {
        return new self('Cache payload failed authentication: wrong key or tampered ciphertext.');
    }

    public static function malformedCompression(): self
    {
        return new self('Cache payload could not be decompressed: the compressed stream is malformed.');
    }

    public static function unserializationFailed(string $serializer): self
    {
        return new self(sprintf('Cache payload could not be decoded by the "%s" serializer.', $serializer));
    }
}
