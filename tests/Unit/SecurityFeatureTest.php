<?php

use PHPUnit\Framework\TestCase;
use Silviooosilva\CacheerPhp\Cacheer;

class SecurityFeatureTest extends TestCase
{
    private Cacheer $cache;
    private string $cacheDir;

    protected function setUp(): void
    {
        $this->cacheDir = __DIR__ . '/cache';
        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0755, true);
        }

        $this->cache = new Cacheer(['cacheDir' => $this->cacheDir]);
    }

    protected function tearDown(): void
    {
        $this->cache->flushCache();
    }

    public function testCompressionFeature(): void
    {
        $this->cache->useCompression();
        $data = ['foo' => 'bar'];

        $this->cache->putCache('compression_key', $data);
        $this->assertTrue($this->cache->isSuccess());

        $cached = $this->cache->getCache('compression_key');
        $this->assertEquals($data, $cached);
    }

    public function testEncryptionFeature(): void
    {
        $this->cache->useEncryption('secret');
        $data = ['foo' => 'bar'];

        $this->cache->putCache('encryption_key', $data);
        $this->assertTrue($this->cache->isSuccess());

        $cached = $this->cache->getCache('encryption_key');
        $this->assertEquals($data, $cached);
    }

    public function testCompressionAndEncryptionTogether(): void
    {
        $this->cache->useCompression();
        $this->cache->useEncryption('secret');
        $data = ['foo' => 'bar'];

        $this->cache->putCache('secure_key', $data);
        $this->assertTrue($this->cache->isSuccess());

        $cached = $this->cache->getCache('secure_key');
        $this->assertEquals($data, $cached);
    }

    /**
     * Encrypting the same payload twice must produce different ciphertexts,
     * proving that a fresh random IV is used on every call.
     */
    public function testEncryptionUsesRandomIv(): void
    {
        $key  = 'my-32-byte-secret-key-for-aes256';
        $data = ['sensitive' => 'payload'];

        // Instantiate two independent caches that write to different keys
        // so each putCache() goes through its own encrypt path.
        $cacheA = new Cacheer(['cacheDir' => $this->cacheDir]);
        $cacheA->useEncryption($key);

        $cacheB = new Cacheer(['cacheDir' => $this->cacheDir]);
        $cacheB->useEncryption($key);

        $cacheA->putCache('iv_test_a', $data);
        $cacheB->putCache('iv_test_b', $data);

        // Read the raw file bytes (the stored envelope's 'data' field is the
        // encrypted blob). They must differ because the IVs are random.
        $fileA = glob($this->cacheDir . '/**/*.cache') ?: glob($this->cacheDir . '/*.cache');
        $this->assertNotEmpty($fileA, 'No cache files were written.');

        // Verify round-trip integrity for both instances.
        $this->assertEquals($data, $cacheA->getCache('iv_test_a'));
        $this->assertEquals($data, $cacheB->getCache('iv_test_b'));

        // The on-disk blobs for the two keys must differ.
        $buildPath = fn(string $cacheKey): string =>
            $this->cacheDir . DIRECTORY_SEPARATOR . md5($cacheKey) . '.cache';

        $blobA = file_get_contents($buildPath('iv_test_a'));
        $blobB = file_get_contents($buildPath('iv_test_b'));
        $this->assertNotEquals($blobA, $blobB, 'Ciphertexts must differ (random IV).');
    }

    /**
     * Data must survive a full encrypt → store → retrieve → decrypt round-trip.
     */
    public function testEncryptionDecryptionRoundtrip(): void
    {
        $key  = 'roundtrip-test-key-32-bytes-long';
        $data = ['user' => 'Alice', 'score' => 99];

        $this->cache->useEncryption($key);
        $this->cache->putCache('roundtrip_key', $data);

        $this->assertTrue($this->cache->isSuccess());

        $retrieved = $this->cache->getCache('roundtrip_key');
        $this->assertEquals($data, $retrieved);
    }
}
