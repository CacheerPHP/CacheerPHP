<?php

declare(strict_types=1);

namespace Silviooosilva\CacheerPhp\Exceptions;

/**
 * Raised when a payload is well-formed but this pipeline cannot read it: an
 * unknown envelope version, a stage id (serializer, compressor, encrypter) the
 * configured pipeline does not provide, or a legacy payload with no v5 reader
 * configured. Distinct from a corrupt payload, which is untrusted rather than
 * merely unsupported.
 */
final class UnsupportedEnvelopeException extends \RuntimeException implements CacheException
{
    /**
     * @return UnsupportedEnvelopeException
     */
    public static function unrecognized(): self
    {
        return new self('Cache payload is not a v6 envelope and no v5 reader is configured.');
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
