<?php

/**
 * Example 11 — AES-256-CBC encryption with random IV (v5.0.0)
 *
 * v4.x used a deterministic IV derived from the encryption key itself,
 * which made ciphertexts for identical payloads identical — a serious
 * cryptographic weakness.
 *
 * v5.0.0 generates a fresh random 16-byte IV for every write.  The IV is
 * prepended to the ciphertext and base64-encoded, so each call to putCache()
 * produces a different ciphertext even for the same data.
 *
 * The decryption path extracts the IV from the stored blob automatically,
 * so no API change is required on the caller side.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Silviooosilva\CacheerPhp\Cacheer;
use Silviooosilva\CacheerPhp\Config\Option\Builder\OptionBuilder;

$encryptionKey = 'my-super-secret-32-byte-aes-key!'; // 32 chars → AES-256

$Options = OptionBuilder::forFile()->dir(__DIR__ . '/cache')->build();
$Cacheer = new Cacheer($Options);
$Cacheer->useEncryption($encryptionKey);

// ── 1. Basic store + retrieve ──────────────────────────────────────────────────

$sensitiveData = [
    'credit_card' => '4111 1111 1111 1111',
    'cvv'         => '123',
    'expiry'      => '12/28',
];

$Cacheer->putCache('payment_info', $sensitiveData);

$retrieved = $Cacheer->getCache('payment_info');

if ($Cacheer->isSuccess()) {
    echo "Decrypted successfully:" . PHP_EOL;
    print_r($retrieved);
}

// ── 2. Random IV — same data produces different on-disk blobs ─────────────────

$Cacheer->putCache('blob_a', 'same payload');
$Cacheer->putCache('blob_b', 'same payload');

$pathA = __DIR__ . '/cache/' . md5('blob_a') . '.cache';
$pathB = __DIR__ . '/cache/' . md5('blob_b') . '.cache';

$sameOnDisk = (file_get_contents($pathA) === file_get_contents($pathB));
echo 'Blobs are identical: ' . ($sameOnDisk ? 'YES (bad!)' : 'NO (good — random IV)') . PHP_EOL;

// ── 3. Combine with compression ────────────────────────────────────────────────

$Cacheer->useCompression(true);

$largePayload = str_repeat('compress-then-encrypt ', 500);
$Cacheer->putCache('compressed_encrypted', $largePayload);

$result = $Cacheer->getCache('compressed_encrypted');
echo 'Payload matches: ' . ($result === $largePayload ? 'YES' : 'NO') . PHP_EOL;

// ── 4. Wrong key = failed decryption ──────────────────────────────────────────

$WrongKeyOptions = OptionBuilder::forFile()->dir(__DIR__ . '/cache')->build();
$WrongKey = new Cacheer($WrongKeyOptions);
$WrongKey->useEncryption('wrong-key-000000000000000000000');

$bad = $WrongKey->getCache('payment_info');
echo 'Data with wrong key: ' . var_export($bad, true) . PHP_EOL;  // null or corrupt

// Clean up
$Cacheer->flushCache();
