<?php

declare(strict_types=1);

namespace Silviooosilva\CacheerPhp\Storage\Compat;

use Silviooosilva\CacheerPhp\Exceptions\CorruptedPayloadException;

/**
 * Reads values written by CacheerPHP v5 during the migration window.
 *
 * v5 transformed payloads with serialize -> gzcompress -> AES-256-CBC (a random
 * 16-byte IV prepended to the ciphertext, then base64). This mirrors that
 * pipeline in reverse. It is constructed with the same compression/encryption
 * settings the v5 application used, since v5 payloads are not self-describing.
 *
 * Note: v5's CBC mode is unauthenticated, so a wrong key or tampering can only
 * be detected as a failed unserialize, not cryptographically. New writes always
 * use the authenticated v6 envelope; this reader exists only to migrate old data.
 */
final class V5PayloadReader
{
    private const CIPHER = 'aes-256-cbc';

    private const IV_BYTES = 16;

    /**
     * @param bool $compression
     * @param ?string $encryptionKey
     */
    public function __construct(
        private readonly bool $compression = false,
        private readonly ?string $encryptionKey = null,
    ) {
    }

    /**
     * @param string $data
     * @return mixed
     */
    public function read(string $data): mixed
    {
        // v5 stored untransformed values verbatim; nothing to decode.
        if (!$this->compression && $this->encryptionKey === null) {
            return $data;
        }

        $payload = $data;

        if ($this->encryptionKey !== null) {
            $payload = $this->decrypt($payload);
        }

        if ($this->compression) {
            $payload = @gzuncompress($payload);
            if ($payload === false) {
                throw CorruptedPayloadException::malformedCompression();
            }
        }

        $value = @unserialize($payload);
        if ($value === false && $payload !== 'b:0;') {
            throw CorruptedPayloadException::unserializationFailed('v5');
        }

        return $value;
    }

    /**
     * @param string $data
     * @return string
     */
    private function decrypt(string $data): string
    {
        $raw = base64_decode($data, true);
        if ($raw === false || strlen($raw) < self::IV_BYTES) {
            throw CorruptedPayloadException::truncatedCiphertext();
        }

        $iv = substr($raw, 0, self::IV_BYTES);
        $plaintext = openssl_decrypt(
            substr($raw, self::IV_BYTES),
            self::CIPHER,
            (string) $this->encryptionKey,
            OPENSSL_RAW_DATA,
            $iv,
        );

        if ($plaintext === false) {
            throw CorruptedPayloadException::authenticationFailed();
        }

        return $plaintext;
    }
}
