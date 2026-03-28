<?php

namespace Silviooosilva\CacheerPhp\Helpers;

use InvalidArgumentException;
use RuntimeException;
use Silviooosilva\CacheerPhp\Exceptions\CacheInvalidArgumentException;

class CacheerHelper
{
    /**
     * Characters forbidden in cache keys (PSR-16 reserved set).
     */
    private const INVALID_KEY_CHARS = '{}()/\\@:';

    /**
     * Validates a cache key, throwing InvalidArgumentException for illegal keys.
     *
     * Rules (PSR-16 compatible):
     *  - Must not be an empty string.
     *  - Must not contain any of the characters: {}()/\@:
     *
     * @param string $key
     * @return void
     * @throws InvalidArgumentException
     */
    public static function validateKey(string $key): void
    {
        if ($key === '') {
            throw new CacheInvalidArgumentException('Cache key must not be empty.');
        }

        if (strpbrk($key, self::INVALID_KEY_CHARS) !== false) {
            throw new CacheInvalidArgumentException(
                "Cache key \"{$key}\" contains one or more reserved characters: " . self::INVALID_KEY_CHARS,
            );
        }
    }

    /**
     * Validates a cache item to ensure it contains the required keys.
     *
     * @param array $item
     * @param callable|null $exceptionFactory
     * @return void
     */
    public static function validateCacheItem(array $item, ?callable $exceptionFactory = null): void
    {
        if (!isset($item['cacheKey']) || !isset($item['cacheData'])) {
            if ($exceptionFactory) {
                throw $exceptionFactory("Each item must contain 'cacheKey' and 'cacheData'");
            }
            throw new InvalidArgumentException("Each item must contain 'cacheKey' and 'cacheData'");
        }
    }

    /**
     * Merges cache data with existing data.
     *
     * @param mixed $cacheData
     * @return array
     */
    public static function mergeCacheData(mixed $cacheData): array
    {
        if (is_array($cacheData) && is_array(reset($cacheData))) {
            $merged = [];
            foreach ($cacheData as $data) {
                $merged[] = $data;
            }
            return $merged;
        }
        return (array)$cacheData;
    }

    /**
     * Generates an array identifier for cache data.
     *
     * @param mixed $currentCacheData
     * @param mixed $cacheData
     * @return array
     */
    public static function arrayIdentifier(mixed $currentCacheData, mixed $cacheData): array
    {
        if (is_array($currentCacheData) && is_array($cacheData)) {
            return array_merge($currentCacheData, $cacheData);
        }
        return array_merge((array)$currentCacheData, (array)$cacheData);
    }

    /**
     * Prepares data for storage, applying compression and/or encryption.
     *
     * Encryption uses AES-256-CBC with a randomly generated IV (16 bytes) that
     * is prepended to the ciphertext and then base64-encoded. This means two
     * encryptions of the same plaintext will always produce different outputs,
     * which is a fundamental requirement for semantic security under CBC mode.
     *
     * @param mixed $data
     * @param bool $compression
     * @param string|null $encryptionKey
     * @return mixed
     * @throws RuntimeException
     */
    public static function prepareForStorage(mixed $data, bool $compression = false, ?string $encryptionKey = null): mixed
    {
        if (!$compression && is_null($encryptionKey)) {
            return $data;
        }

        $payload = serialize($data);

        if ($compression) {
            $payload = gzcompress($payload);
        }

        if (!is_null($encryptionKey)) {
            // Generate a cryptographically random IV for each encryption call.
            // A fixed/deterministic IV breaks CBC's semantic security guarantees.
            $iv = openssl_random_pseudo_bytes(16);
            $encrypted = openssl_encrypt($payload, 'AES-256-CBC', $encryptionKey, OPENSSL_RAW_DATA, $iv);

            if ($encrypted === false) {
                throw new RuntimeException('Failed to encrypt cache data.');
            }

            // Prepend the IV to the ciphertext, then base64-encode the whole thing
            // so it can be stored as a plain string in any backend.
            $payload = base64_encode($iv . $encrypted);
        }

        return $payload;
    }

    /**
     * Recovers data from storage, applying decompression and/or decryption.
     *
     * Expects the encrypted payload to be a base64-encoded string with the
     * 16-byte IV prepended to the ciphertext (as produced by prepareForStorage).
     *
     * @param mixed $data
     * @param bool $compression
     * @param string|null $encryptionKey
     * @return mixed
     * @throws RuntimeException
     */
    public static function recoverFromStorage(mixed $data, bool $compression = false, ?string $encryptionKey = null): mixed
    {
        if (!$compression && is_null($encryptionKey)) {
            return $data;
        }

        if (!is_null($encryptionKey)) {
            $raw = base64_decode($data, true);

            if ($raw === false || strlen($raw) < 16) {
                throw new RuntimeException('Failed to decode encrypted cache data: invalid payload.');
            }

            // First 16 bytes are the IV; the rest is the actual ciphertext.
            $iv = substr($raw, 0, 16);
            $cipher = substr($raw, 16);
            $decrypted = openssl_decrypt($cipher, 'AES-256-CBC', $encryptionKey, OPENSSL_RAW_DATA, $iv);

            if ($decrypted === false) {
                throw new RuntimeException('Failed to decrypt cache data. Wrong key or corrupted payload.');
            }

            $data = $decrypted;
        }

        if ($compression) {
            $data = gzuncompress($data);
        }

        return unserialize($data);
    }
}
