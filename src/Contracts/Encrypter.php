<?php

declare(strict_types=1);

namespace Silviooosilva\CacheerPhp\Contracts;

/**
 * Optional authenticated-encryption stage of the storage pipeline.
 *
 * Encryption must be authenticated: decrypt() must fail for any tampered or
 * truncated ciphertext instead of returning unauthenticated bytes. The active
 * key id is recorded in the envelope so that rotated keys can still decrypt
 * older entries while new writes use the current key.
 */
interface Encrypter
{
    /**
     * Stable, envelope-safe identifier (ASCII, no 0x1E separators), e.g. "aes-256-gcm".
     *
     * @return string
     */
    public function id(): string;

    /**
     * Identifier of the key used for new writes.
     *
     * @return string
     */
    public function activeKeyId(): string;

    /**
     * @param string $plaintext
     * @return string
     */
    public function encrypt(string $plaintext): string;

    /**
     * @param string $ciphertext
     * @param string $keyId The key id recorded alongside the ciphertext at write time.
     * @return string
     */
    public function decrypt(string $ciphertext, string $keyId): string;
}
