<?php

declare(strict_types=1);

namespace Silviooosilva\CacheerPhp\Exceptions;

/**
 * Raised when a payload is well-formed but this pipeline cannot read it: an
 * unknown envelope version, a stage id (serializer, compressor, encrypter) the
 * configured pipeline does not provide, or a blob that is not a v6 envelope at
 * all (such as a v5 payload). Distinct from a corrupt payload, which is untrusted rather than
 * merely unsupported.
 */
final class UnsupportedEnvelopeException extends \RuntimeException implements CacheException
{
    /**
     * @return UnsupportedEnvelopeException
     */
    public static function unrecognized(): self
    {
        return new self('Cache payload is not a v6 envelope.');
    }

    /**
     * @return UnsupportedEnvelopeException
     */
    public static function unencrypted(): self
    {
        return new self('Cache envelope is not encrypted, but this pipeline only reads encrypted values.');
    }

    /**
     * @param int $version
     * @return UnsupportedEnvelopeException
     */
    public static function version(int $version): self
    {
        return new self(sprintf('Unsupported cache envelope version %d.', $version));
    }

    /**
     * @param string $stage
     * @param string $id
     * @return UnsupportedEnvelopeException
     */
    public static function stage(string $stage, string $id): self
    {
        return new self(sprintf(
            'Cache envelope requires the "%s" %s, which this pipeline does not provide.',
            $id,
            $stage,
        ));
    }
}
