<?php

declare(strict_types=1);

namespace Silviooosilva\CacheerPhp\Storage\Encryption;

use RuntimeException;
use Silviooosilva\CacheerPhp\Contracts\Encrypter;
use Silviooosilva\CacheerPhp\Exceptions\CorruptedPayloadException;

/**
 * Authenticated encryption with AES-256-GCM via ext-openssl.
 *
 * Unlike v5's unauthenticated AES-256-CBC, GCM binds an authentication tag to
 * every value: decrypt() rejects any ciphertext produced with the wrong key or
 * altered after the fact instead of returning garbage plaintext. Each value
 * gets a fresh random nonce. The stored form is nonce(12) . tag(16) . ciphertext.
 */
final class OpenSslGcmEncrypter implements Encrypter
{
    private const CIPHER = 'aes-256-gcm';

    private const NONCE_BYTES = 12;

    private const TAG_BYTES = 16;

    /**
     * @param Keyring $keyring
     */
    public function __construct(private readonly Keyring $keyring)
    {
        if (!in_array(self::CIPHER, openssl_get_cipher_methods(), true)) {
            throw new RuntimeException('The openssl extension with AES-256-GCM support is required for encryption.');
        }
    }

    /**
     * @return string
     */
    public function id(): string
    {
        return self::CIPHER;
    }

    /**
     * @return string
     */
    public function activeKeyId(): string
    {
        return $this->keyring->activeId();
    }

    /**
     * @param string $plaintext
     * @return string
     */
    public function encrypt(string $plaintext): string
    {
        $nonce = random_bytes(self::NONCE_BYTES);
        $tag = '';

        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            $this->keyring->activeKey(),
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            '',
            self::TAG_BYTES,
        );

        if ($ciphertext === false) {
            throw new RuntimeException('Failed to encrypt cache value.');
        }

        return $nonce . $tag . $ciphertext;
    }

    /**
     * @param string $ciphertext
     * @param string $keyId
     * @return string
     */
    public function decrypt(string $ciphertext, string $keyId): string
    {
        if (strlen($ciphertext) < self::NONCE_BYTES + self::TAG_BYTES) {
            throw CorruptedPayloadException::truncatedCiphertext();
        }

        $nonce = substr($ciphertext, 0, self::NONCE_BYTES);
        $tag = substr($ciphertext, self::NONCE_BYTES, self::TAG_BYTES);
        $body = substr($ciphertext, self::NONCE_BYTES + self::TAG_BYTES);

        $plaintext = openssl_decrypt(
            $body,
            self::CIPHER,
            $this->keyring->keyFor($keyId),
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
        );

        if ($plaintext === false) {
            throw CorruptedPayloadException::authenticationFailed();
        }

        return $plaintext;
    }
}
