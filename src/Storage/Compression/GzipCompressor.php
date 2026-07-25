<?php

declare(strict_types=1);

namespace Silviooosilva\CacheerPhp\Storage\Compression;

use RuntimeException;
use Silviooosilva\CacheerPhp\Contracts\Compressor;
use Silviooosilva\CacheerPhp\Exceptions\CorruptedPayloadException;
use Silviooosilva\CacheerPhp\Exceptions\ValueTooLargeException;

/**
 * Zlib (RFC 1950) compression via ext-zlib.
 *
 * Decompression feeds the compressed input in small slices and checks the
 * accumulated output after each slice, so a hostile payload is rejected once
 * it crosses the ceiling instead of being inflated in full. This bounds worst-
 * case memory rather than trusting the stream's declared size.
 */
final class GzipCompressor implements Compressor
{
    private const INPUT_CHUNK_BYTES = 4096;

    public function __construct(private readonly int $level = 6)
    {
        if (!function_exists('gzcompress')) {
            throw new RuntimeException('The zlib extension is required for gzip compression.');
        }

        if ($level < 0 || $level > 9) {
            throw new RuntimeException('Gzip compression level must be between 0 and 9.');
        }
    }

    public function id(): string
    {
        return 'gzip';
    }

    public function compress(string $data): string
    {
        $compressed = gzcompress($data, $this->level);

        if ($compressed === false) {
            throw new RuntimeException('Failed to compress cache value.');
        }

        return $compressed;
    }

    public function decompress(string $data, int $maxBytes = 0): string
    {
        $context = inflate_init(ZLIB_ENCODING_DEFLATE);

        if ($context === false) {
            throw CorruptedPayloadException::malformedCompression();
        }

        $output = '';
        $length = strlen($data);
        $offset = 0;

        do {
            $slice = substr($data, $offset, self::INPUT_CHUNK_BYTES);
            $offset += self::INPUT_CHUNK_BYTES;
            $flush = $offset >= $length ? ZLIB_FINISH : ZLIB_SYNC_FLUSH;

            // inflate_add() raises a PHP warning on a malformed stream in
            // addition to returning false; swallow it so a corrupt payload is
            // reported purely as a typed exception.
            set_error_handler(static fn (): bool => true);
            try {
                $piece = inflate_add($context, $slice, $flush);
            } finally {
                restore_error_handler();
            }

            if ($piece === false) {
                throw CorruptedPayloadException::malformedCompression();
            }

            $output .= $piece;
            if ($maxBytes > 0 && strlen($output) > $maxBytes) {
                throw ValueTooLargeException::onRead($maxBytes);
            }
        } while ($offset < $length);

        return $output;
    }
}
