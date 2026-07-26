<?php

declare(strict_types=1);

namespace Tests\Storage;

use PDO;
use PHPUnit\Framework\TestCase;
use Silviooosilva\CacheerPhp\Config\PipelineConfig;
use Silviooosilva\CacheerPhp\Kernel\Key;
use Silviooosilva\CacheerPhp\Storage\Compat\V5PayloadReader;
use Silviooosilva\CacheerPhp\Storage\Envelope;
use Silviooosilva\CacheerPhp\Storage\EnvelopeCodec;
use Silviooosilva\CacheerPhp\Storage\KeyEncoder\HashingKeyEncoder;
use Silviooosilva\CacheerPhp\Stores\DatabaseStore;
use Silviooosilva\CacheerPhp\Stores\FileStore;
use Silviooosilva\CacheerPhp\Stores\Support\DatabaseStoreSchema;
use Silviooosilva\CacheerPhp\Stores\Support\StoredRecord;
use Tests\Support\FakeClock;

/**
 * Covers the Milestone 7 migration behavior: a store configured with a v5 reader
 * decodes legacy values and, when asked, rewrites them in the v6 envelope in
 * place while preserving their timestamps.
 */
final class RewriteOnReadTest extends TestCase
{
    private FakeClock $clock;

    private string $dir;

    protected function setUp(): void
    {
        $this->clock = new FakeClock();
        $this->dir = sys_get_temp_dir() . '/cacheer-migrate-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        if (is_dir($this->dir)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($iterator as $item) {
                $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
            }
            @rmdir($this->dir);
        }
    }

    public function testCodecRecognizesLegacyBlobsOnlyWithAReader(): void
    {
        $plain = PipelineConfig::default()->codec();
        self::assertFalse($plain->isLegacyBlob('anything'));
        self::assertFalse($plain->isLegacyBlob($plain->encode('v6 value')));

        $migrating = $this->legacyCodec();
        self::assertTrue($migrating->isLegacyBlob('a v5 payload'));
        self::assertFalse($migrating->isLegacyBlob($migrating->encode('v6 value')));
    }

    public function testFileStoreRewritesLegacyValueOnRead(): void
    {
        $key = Key::named('legacy:file');
        $this->seedLegacyFile($key, 'legacy-value', createdAt: 1_000, expiresAt: null);

        $store = new FileStore($this->dir, $this->legacyCodec(), clock: $this->clock, migrateLegacyOnRead: true);

        $entry = $store->get($key);
        self::assertTrue($entry->isHit());
        self::assertSame('legacy-value', $entry->value());
        self::assertSame(1_000, $entry->createdAt());

        // The blob on disk is now a v6 envelope, still readable without the reader.
        $record = StoredRecord::fromString((string) file_get_contents($this->pathFor($key)));
        self::assertNotNull($record);
        self::assertTrue(Envelope::isEnvelope($record->blob));
        self::assertSame(1_000, $record->createdAt);
        self::assertSame('legacy-value', PipelineConfig::default()->codec()->decode($record->blob));
    }

    public function testFileStoreLeavesLegacyValueUntouchedWhenMigrationDisabled(): void
    {
        $key = Key::named('legacy:file:off');
        $this->seedLegacyFile($key, 'legacy-value', createdAt: 1_000, expiresAt: null);

        $store = new FileStore($this->dir, $this->legacyCodec(), clock: $this->clock);
        self::assertSame('legacy-value', $store->get($key)->value());

        $record = StoredRecord::fromString((string) file_get_contents($this->pathFor($key)));
        self::assertNotNull($record);
        self::assertFalse(Envelope::isEnvelope($record->blob));
    }

    public function testDatabaseStoreRewritesLegacyValueOnRead(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        DatabaseStoreSchema::migrate($pdo, 'cacheer_store');

        $key = Key::named('legacy:db');
        $encoder = new HashingKeyEncoder();
        $pdo->prepare(
            'INSERT INTO cacheer_store (cache_key, scope, key_value, value, created_at, expires_at)
             VALUES (:key, :scope, :kv, :value, :created, :expires)',
        )->execute([
            ':key'     => $encoder->encode($key),
            ':scope'   => '',
            ':kv'      => $key->value(),
            ':value'   => base64_encode('legacy-value'),
            ':created' => 1_000,
            ':expires' => null,
        ]);

        $store = new DatabaseStore($pdo, 'cacheer_store', $this->legacyCodec(), clock: $this->clock, migrateLegacyOnRead: true);
        self::assertSame('legacy-value', $store->get($key)->value());

        $raw = $pdo->query('SELECT value, created_at FROM cacheer_store')->fetch(PDO::FETCH_ASSOC);
        $blob = (string) base64_decode((string) $raw['value'], true);
        self::assertTrue(Envelope::isEnvelope($blob));
        self::assertSame('1000', (string) $raw['created_at']);
        self::assertSame('legacy-value', PipelineConfig::default()->codec()->decode($blob));
    }

    private function legacyCodec(): EnvelopeCodec
    {
        return PipelineConfig::default()->withV5Reader(new V5PayloadReader())->codec();
    }

    private function seedLegacyFile(Key $key, string $v5Blob, int $createdAt, ?int $expiresAt): void
    {
        $record = StoredRecord::forKey($key, $createdAt, $expiresAt, $v5Blob);
        $path = $this->pathFor($key);
        @mkdir(dirname($path), 0775, true);
        file_put_contents($path, $record->toString());
    }

    private function pathFor(Key $key): string
    {
        $encoded = (new HashingKeyEncoder())->encode($key);
        $safe = hash('sha256', $encoded);

        return $this->dir . '/entries/' . substr($safe, 0, 2) . '/' . $safe . '.cache';
    }
}
