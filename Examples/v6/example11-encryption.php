<?php

declare(strict_types=1);

/**
 * Example 11 — Compression + authenticated encryption (v6)
 *
 * v5 → v6 mapping:
 *   $c->useEncryption($key)      →  Cacheer::build()->encryptWithPassphrases([...], 'current')
 *   $c->useCompression(true)     →  Cacheer::build()->gzip()
 *
 * v6 encrypts with AES-256-GCM (authenticated) through the storage pipeline, and
 * supports keyrings with a rotating "active" id so you can re-key without losing
 * old data. A fresh random nonce per write means identical payloads never
 * produce identical ciphertext, and tampering is detected on read.
 *
 * Run: php Examples/v6/example11-encryption.php
 */

require __DIR__ . '/../../vendor/autoload.php';

use Silviooosilva\CacheerPhp\Cacheer;

$dir = __DIR__ . '/cache';

$cache = Cacheer::build()
    ->file($dir)
    ->gzip()
    ->encryptWithPassphrases(['current' => 'my-super-secret-passphrase'], 'current')
    ->create();

// ── 1. Store + retrieve sensitive data ───────────────────────────────────────
$sensitive = [
    'credit_card' => '4111 1111 1111 1111',
    'cvv' => '123',
    'expiry' => '12/28',
];
$cache->set('payment_info', $sensitive);

$retrieved = $cache->get('payment_info');
echo "Decrypted successfully:\n";
print_r($retrieved);
assert($retrieved === $sensitive);

// ── 2. Compress-then-encrypt round-trips a large payload ─────────────────────
$large = str_repeat('compress-then-encrypt ', 500);
$cache->set('compressed_encrypted', $large);
echo 'Large payload matches: ' . ($cache->get('compressed_encrypted') === $large ? 'YES' : 'NO') . PHP_EOL;
assert($cache->get('compressed_encrypted') === $large);

// ── 3. A different passphrase cannot read the ciphertext ─────────────────────
$wrong = Cacheer::build()
    ->file($dir)
    ->gzip()
    ->encryptWithPassphrases(['current' => 'a-totally-different-passphrase'], 'current')
    ->create();

$leaked = null;
try {
    $leaked = $wrong->get('payment_info');
} catch (\Throwable $e) {
    echo 'Wrong key rejected: ' . $e::class . PHP_EOL;
}
echo 'Recovered original with wrong key: ' . ($leaked === $sensitive ? 'YES (bad!)' : 'NO (good)') . PHP_EOL;
assert($leaked !== $sensitive);

$cache->clear();

echo "OK\n";
