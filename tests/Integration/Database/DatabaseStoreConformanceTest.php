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

    public function testBatchReadsAndWritesPrepareOneStatement(): void
    {
        $pdo = new class ('sqlite::memory:') extends PDO {
            /**
             * @var list<string>
             */
            public array $prepared = [];

            public function prepare(string $query, array $options = []): \PDOStatement|false
            {
                $this->prepared[] = strtok(ltrim($query), ' ');

                return parent::prepare($query, $options);
            }
        };
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        DatabaseStoreSchema::migrate($pdo, 'cacheer_store');
        $store = new DatabaseStore($pdo, 'cacheer_store', clock: $this->clock);

        $keys = array_map(static fn (int $i): Key => Key::named('item:' . $i), range(1, 100));
        $store->setMany(array_map(static fn (Key $key): array => ['key' => $key, 'value' => $key->value()], $keys), Ttl::forever());
        self::assertSame(['INSERT'], $pdo->prepared, 'setMany() prepares its upsert once.');

        $store->set(Key::named('expiring'), 'v', Ttl::seconds(1));
        $this->clock->advance(2);
        $pdo->prepared = [];

        $requested = [$keys[2], Key::named('missing'), $keys[0], Key::named('expiring'), $keys[2]];
        $entries = $store->getMany($requested);

        self::assertSame(['SELECT'], array_values(array_filter($pdo->prepared, static fn (string $verb): bool => $verb === 'SELECT')), 'getMany() reads with one query.');
        self::assertSame(['item:3', null, 'item:1', null, 'item:3'], array_map(static fn ($entry): mixed => $entry->valueOr(null), $entries));
        self::assertSame(['item:3', 'missing', 'item:1', 'expiring', 'item:3'], array_map(static fn ($entry): string => $entry->key()->value(), $entries));
        self::assertSame(100, (int) $pdo->query('SELECT COUNT(*) FROM cacheer_store')->fetchColumn(), 'Only the expired entry was removed.');
    }

    public function testBatchWritesRunOneStatementPerChunk(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        DatabaseStoreSchema::migrate($pdo, 'cacheer_store');
        $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, [CountingStatement::class]);
        CountingStatement::$executed = [];
        $store = new DatabaseStore($pdo, 'cacheer_store', clock: $this->clock);

        $entries = array_map(static fn (int $i): array => ['key' => Key::named('item:' . $i), 'value' => 'v' . $i], range(1, 250));
        $entries[] = ['key' => Key::named('item:1'), 'value' => 'last write wins'];
        $store->setMany($entries, Ttl::forever());

        $inserts = array_values(array_filter(CountingStatement::$executed, static fn (string $verb): bool => $verb === 'INSERT'));
        self::assertCount(3, $inserts, '250 distinct entries are three statements of up to 100 rows.');
        self::assertSame('last write wins', $store->get(Key::named('item:1'))->value());
        self::assertSame('v250', $store->get(Key::named('item:250'))->value());
        self::assertSame(250, (int) $pdo->query('SELECT COUNT(*) FROM cacheer_store')->fetchColumn());
    }
}

/**
 * Records the verb of every executed statement.
 */
final class CountingStatement extends \PDOStatement
{
    /**
     * @var list<string>
     */
    public static array $executed = [];

    protected function __construct()
    {
    }

    public function execute(?array $params = null): bool
    {
        self::$executed[] = (string) strtok(ltrim($this->queryString), ' ');

        return parent::execute($params);
    }
}
