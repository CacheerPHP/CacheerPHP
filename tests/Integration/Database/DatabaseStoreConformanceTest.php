<?php

declare(strict_types=1);

namespace Tests\Integration\Database;

use InvalidArgumentException;
use PDO;
use Silviooosilva\CacheerPhp\Contracts\Store;
use Silviooosilva\CacheerPhp\Exceptions\InvalidScopeException;
use Silviooosilva\CacheerPhp\Kernel\Key;
use Silviooosilva\CacheerPhp\Kernel\Scope;
use Silviooosilva\CacheerPhp\Kernel\Ttl;
use Silviooosilva\CacheerPhp\Stores\DatabaseStore;
use Silviooosilva\CacheerPhp\Stores\Support\DatabaseStoreSchema;
use Tests\Support\FakeClock;
use Tests\Support\StoreConformance;

final class DatabaseStoreConformanceTest extends StoreConformance
{
    private PDO $pdo;

    protected function createStore(FakeClock $clock): Store
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        DatabaseStoreSchema::migrate($this->pdo, 'cacheer_store');

        return new DatabaseStore($this->pdo, 'cacheer_store', clock: $clock);
    }

    /**
     * The scope and tag columns hold 255 characters on MySQL and PostgreSQL,
     * which reject or (MySQL non-strict) silently truncate longer values. The
     * store enforces the same limit on every driver, before writing anything.
     */
    public function testScopesLongerThanTheColumnAreRejectedBeforeWriting(): void
    {
        $store = $this->databaseStore();
        $fits = Scope::named(str_repeat('a', 200))->child(str_repeat('é', 54)); // 255 characters
        $tooLong = $fits->child('x');

        $store->set(Key::named('k')->within($fits), 'fits', Ttl::forever());
        self::assertSame('fits', $store->get(Key::named('k')->within($fits))->value());

        foreach ([
            'set'       => static fn () => $store->set(Key::named('k')->within($tooLong), 'v', Ttl::forever()),
            'increment' => static fn () => $store->increment(Key::named('n')->within($tooLong)),
            'setMany'   => static fn () => $store->setMany([
                ['key' => Key::named('batch'), 'value' => 'v'],
                ['key' => Key::named('k')->within($tooLong), 'value' => 'v'],
            ], Ttl::forever()),
        ] as $operation => $attempt) {
            try {
                $attempt();
                self::fail(sprintf('%s() must reject a scope longer than 255 characters.', $operation));
            } catch (InvalidScopeException) {
            }
        }

        self::assertTrue($store->get(Key::named('batch'))->isMiss(), 'A rejected batch must write nothing.');
        self::assertSame(1, $this->rowCount());
    }

    public function testTagsLongerThanTheColumnAreRejectedBeforeWriting(): void
    {
        $store = $this->databaseStore();
        $key = Key::named('k');
        $store->set($key, 'v', Ttl::forever());

        try {
            $store->tag($key, 'short', str_repeat('t', 256));
            self::fail('tag() must reject a tag longer than 255 characters.');
        } catch (InvalidArgumentException) {
        }

        self::assertSame(0, $store->clearTag('short'), 'A rejected tag() call must record no tags.');

        $store->tag($key, str_repeat('t', 255));
        self::assertSame(1, $store->clearTag(str_repeat('t', 255)));
    }

    private function databaseStore(): DatabaseStore
    {
        self::assertInstanceOf(DatabaseStore::class, $this->store);

        return $this->store;
    }

    private function rowCount(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM cacheer_store')->fetchColumn();
    }
}
