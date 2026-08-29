<?php

declare(strict_types=1);

namespace Silviooosilva\CacheerPhp\Contracts;

/**
 * Optional payload compression stage of the storage pipeline.
 *
 * decompress() takes a byte ceiling so a malicious or corrupt payload cannot
 * be inflated without bound (a "zip bomb"); implementations must stop and fail
 * once the ceiling is crossed rather than allocate the full output.
 */
interface Compressor
{
    /**
     * Stable, envelope-safe identifier (ASCII, no 0x1E separators), e.g. "gzip".
     *
     * @return string
     */
    public function id(): string;

    /**
     * @param string $data
     * @return string
     */
    public function compress(string $data): string;

    /**
     * @param string $data
     * @param int $maxBytes Hard ceiling for the decompressed output; 0 disables the check.
     * @return string
     */
    public function decompress(string $data, int $maxBytes = 0): string;
}
