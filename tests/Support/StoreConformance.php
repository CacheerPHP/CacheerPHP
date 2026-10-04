<?php

declare(strict_types=1);

namespace Tests\Support;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Silviooosilva\CacheerPhp\Contracts\AtomicStore;
use Silviooosilva\CacheerPhp\Contracts\BatchStore;
use Silviooosilva\CacheerPhp\Contracts\FlushableScopeStore;
use Silviooosilva\CacheerPhp\Contracts\InspectableStore;
use Silviooosilva\CacheerPhp\Contracts\LockingStore;
use Silviooosilva\CacheerPhp\Contracts\PrunableStore;
use Silviooosilva\CacheerPhp\Contracts\Store;
use Silviooosilva\CacheerPhp\Contracts\TaggableStore;
use Silviooosilva\CacheerPhp\Contracts\TouchStore;
use Silviooosilva\CacheerPhp\Exceptions\StoreOperationFailedException;
use Silviooosilva\CacheerPhp\Kernel\Key;
use Silviooosilva\CacheerPhp\Kernel\Scope;
use Silviooosilva\CacheerPhp\Kernel\Ttl;

/**
 * The one contract suite every built-in v6 store must pass.
 *
 * Base behavior is verified for all stores; each capability block is skipped
 * for stores that do not implement it, so a driver is only ever asserted to
 * guarantee what it actually provides.
 */
abstract class StoreConformance extends TestCase
{
    protected FakeClock $clock;

    protected Store $store;

    /**
     * Build a fresh, empty store bound to the given clock.
     */
    abstract protected function createStore(FakeClock $clock): Store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clock = new FakeClock();
        $this->store = $this->createStore($this->clock);
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function values(): array
    {
        return [
            'scalar'       => ['cacheer'],
            'integer'      => [42],
            'float'        => [3.14],
            'false'        => [false],
            'zero'         => [0],
            'empty string' => [''],
            'empty array'  => [[]],
            'null'         => [null],
            'list'         => [[1, 2, 3]],
            'assoc'        => [['framework' => 'agnostic', 'nested' => ['x' => 1]]],
            'object'       => [(object) ['type' => 'conformance', 'version' => 6]],
        ];
    }

    #[DataProvider('values')]
    public function testStoreAndGetRoundTripsEveryValueType(mixed $value): void
    {
        $key = Key::named('round-trip');

        self::assertTrue($this->store->get($key)->isMiss());

        $this->store->set($key, $value, Ttl::forever());
        $entry = $this->store->get($key);

        self::assertTrue($entry->isHit());
        self::assertEquals($value, $entry->value());
    }

    public function testCachedNullIsAHitNotAMiss(): void
    {
        $key = Key::named('nullable');
        $this->store->set($key, null, Ttl::forever());

        self::assertTrue($this->store->get($key)->isHit());
        self::assertNull($this->store->get($key)->value());
    }

    public function testDeleteReportsWhetherSomethingWasRemoved(): void
    {
        $key = Key::named('doomed');

        self::assertFalse($this->store->delete($key));

        $this->store->set($key, 'value', Ttl::forever());
        self::assertTrue($this->store->delete($key));
        self::assertTrue($this->store->get($key)->isMiss());
    }

    public function testClearEmptiesTheStore(): void
    {
        $this->store->set(Key::named('a'), 1, Ttl::forever());
        $this->store->set(Key::named('b'), 2, Ttl::forever());

        $this->store->clear();

        self::assertTrue($this->store->get(Key::named('a'))->isMiss());
        self::assertTrue($this->store->get(Key::named('b'))->isMiss());
    }

    public function testExpirationUsesTheInjectedClock(): void
    {
        $key = Key::named('ttl');
        $this->store->set($key, 'value', Ttl::seconds(10));

        $this->clock->advance(9);
        self::assertTrue($this->store->get($key)->isHit());

        $this->clock->advance(1);
        self::assertTrue($this->store->get($key)->isMiss());
    }

    public function testForeverEntriesDoNotExpire(): void
    {
        $key = Key::named('permanent');
        $this->store->set($key, 'value', Ttl::forever());

        $this->clock->advance(10_000_000);

        $entry = $this->store->get($key);
        self::assertTrue($entry->isHit());
        self::assertNull($entry->expiresAt());
    }

    public function testOverwriteReplacesValueAndExpiry(): void
    {
        $key = Key::named('mutable');
        $this->store->set($key, 'first', Ttl::seconds(5));
        $this->store->set($key, 'second', Ttl::forever());

        $this->clock->advance(10);

        self::assertSame('second', $this->store->get($key)->value());
    }

    public function testScopesIsolateIdenticalKeyNames(): void
    {
        $root = Key::named('same');
        $tenant = Key::named('same')->within(Scope::named('tenant'));

        $this->store->set($root, 'root-value', Ttl::forever());
        $this->store->set($tenant, 'tenant-value', Ttl::forever());

        self::assertSame('root-value', $this->store->get($root)->value());
        self::assertSame('tenant-value', $this->store->get($tenant)->value());
    }

    public function testBatchOperationsPreserveOrderAndRepresentMisses(): void
    {
        $store = $this->requireCapability(BatchStore::class);

        $first = Key::named('first');
        $second = Key::named('second');
        $missing = Key::named('missing');

        $store->setMany([
            ['key' => $first, 'value' => 1],
            ['key' => $second, 'value' => null],
        ], Ttl::forever());

        $entries = $store->getMany([$second, $missing, $first]);

        self::assertCount(3, $entries);
        self::assertNull($entries[0]->value());
        self::assertTrue($entries[1]->isMiss());
        self::assertSame(1, $entries[2]->value());

        self::assertTrue($store->deleteMany([$first, $second]));
        self::assertFalse($store->deleteMany([$missing]));
    }

    public function testTouchExtendsExpiryAndReportsMisses(): void
    {
        $store = $this->requireCapability(TouchStore::class);
        $key = Key::named('touchable');

        self::assertFalse($store->touch($key, Ttl::seconds(10)));

        $this->store->set($key, 'value', Ttl::seconds(5));
        self::assertTrue($store->touch($key, Ttl::seconds(60)));

        $this->clock->advance(30);
        self::assertTrue($this->store->get($key)->isHit());
    }

    public function testPruneRemovesExpiredEntries(): void
    {
        $store = $this->requireCapability(PrunableStore::class);

        $this->store->set(Key::named('keep'), 'value', Ttl::forever());
        $this->store->set(Key::named('drop'), 'value', Ttl::seconds(1));

        $this->clock->advance(2);

        self::assertSame(1, $store->prune());
        self::assertTrue($this->store->get(Key::named('keep'))->isHit());
    }

    public function testInspectionIsScopedAndSkipsExpiredEntries(): void
    {
        $store = $this->requireCapability(InspectableStore::class);
        $tenant = Scope::named('tenant');

        $this->store->set(Key::named('root'), 'r', Ttl::forever());
        $this->store->set(Key::named('a')->within($tenant), 1, Ttl::forever());
        $this->store->set(Key::named('b')->within($tenant->child('nested')), 2, Ttl::forever());
        $this->store->set(Key::named('gone')->within($tenant), 3, Ttl::seconds(1));

        $this->clock->advance(2);

        $entries = iterator_to_array($store->entries($tenant), false);
        self::assertCount(2, $entries);
    }

    public function testClearScopeRemovesNestedScopesOnly(): void
    {
        $store = $this->requireCapability(FlushableScopeStore::class);
        $tenant = Scope::named('tenant');

        $this->store->set(Key::named('root'), 'r', Ttl::forever());
        $this->store->set(Key::named('user')->within($tenant), 'u', Ttl::forever());
        $this->store->set(Key::named('profile')->within($tenant->child('nested')), 'p', Ttl::forever());

        $store->clearScope($tenant);

        self::assertTrue($this->store->get(Key::named('user')->within($tenant))->isMiss());
        self::assertTrue($this->store->get(Key::named('profile')->within($tenant->child('nested')))->isMiss());
        self::assertSame('r', $this->store->get(Key::named('root'))->value());
    }

    /**
     * Scope segments may contain characters that are wildcards or escapes in a
     * backend's pattern language (SQL LIKE `%`, `_`, `\`). Each target scope is
     * paired with a neighbour that such a pattern would wrongly match.
     *
     * @return array<string, array{string, string}>
     */
    public static function patternLookalikeScopes(): array
    {
        return [
            'underscore' => ['tenant_a', 'tenantXa'],
            'percent'    => ['a%', 'abc'],
            'backslash'  => ['a\\', 'a'],
        ];
    }

    #[DataProvider('patternLookalikeScopes')]
    public function testClearScopeTreatsScopeNamesLiterally(string $target, string $neighbour): void
    {
        $store = $this->requireCapability(FlushableScopeStore::class);
        $scope = Scope::named($target);
        $other = Scope::named($neighbour);

        $this->store->set(Key::named('own')->within($scope), 'own', Ttl::forever());
        $this->store->set(Key::named('nested')->within($scope->child('x')), 'nested', Ttl::forever());
        $this->store->set(Key::named('other')->within($other), 'other', Ttl::forever());
        $this->store->set(Key::named('other-nested')->within($other->child('x')), 'other-nested', Ttl::forever());

        $store->clearScope($scope);

        self::assertTrue($this->store->get(Key::named('own')->within($scope))->isMiss());
        self::assertTrue($this->store->get(Key::named('nested')->within($scope->child('x')))->isMiss());
        self::assertSame('other', $this->store->get(Key::named('other')->within($other))->value());
        self::assertSame(
            'other-nested',
            $this->store->get(Key::named('other-nested')->within($other->child('x')))->value(),
            'Clearing one scope must not reach a neighbouring scope.',
        );
    }

    #[DataProvider('patternLookalikeScopes')]
    public function testInspectionTreatsScopeNamesLiterally(string $target, string $neighbour): void
    {
        $store = $this->requireCapability(InspectableStore::class);
        $scope = Scope::named($target);
        $other = Scope::named($neighbour);

        $this->store->set(Key::named('own')->within($scope), 'own', Ttl::forever());
        $this->store->set(Key::named('nested')->within($scope->child('x')), 'nested', Ttl::forever());
        $this->store->set(Key::named('other')->within($other), 'other', Ttl::forever());
        $this->store->set(Key::named('other-nested')->within($other->child('x')), 'other-nested', Ttl::forever());

        $values = array_map(
            static fn ($entry): mixed => $entry->value(),
            iterator_to_array($store->entries($scope), false),
        );
        sort($values);

        self::assertSame(['nested', 'own'], $values);
    }

    /**
     * Names that differ only by case, accent, or Unicode normalization are
     * distinct scopes and tags; a backend collation must not merge them.
     *
     * @return array<string, array{string, string}>
     */
    public static function collationLookalikeNames(): array
    {
        return [
            'case'          => ['Tenant', 'tenant'],
            'accent'        => ['café', 'cafe'],
            'normalization' => ["caf\u{00E9}", "cafe\u{0301}"],
        ];
    }

    #[DataProvider('collationLookalikeNames')]
    public function testClearScopeDistinguishesCaseAndUnicode(string $target, string $neighbour): void
    {
        $store = $this->requireCapability(FlushableScopeStore::class);
        $scope = Scope::named($target);
        $other = Scope::named($neighbour);

        $this->store->set(Key::named('own')->within($scope->child('x')), 'own', Ttl::forever());
        $this->store->set(Key::named('other')->within($other), 'other', Ttl::forever());
        $this->store->set(Key::named('other-nested')->within($other->child('x')), 'other-nested', Ttl::forever());

        $store->clearScope($scope);

        self::assertTrue($this->store->get(Key::named('own')->within($scope->child('x')))->isMiss());
        self::assertSame('other', $this->store->get(Key::named('other')->within($other))->value());
        self::assertSame('other-nested', $this->store->get(Key::named('other-nested')->within($other->child('x')))->value());
    }

    #[DataProvider('collationLookalikeNames')]
    public function testInspectionDistinguishesCaseAndUnicode(string $target, string $neighbour): void
    {
        $store = $this->requireCapability(InspectableStore::class);
        $scope = Scope::named($target);
        $other = Scope::named($neighbour);

        $this->store->set(Key::named('own')->within($scope), 'own', Ttl::forever());
        $this->store->set(Key::named('other')->within($other), 'other', Ttl::forever());

        $values = array_map(
            static fn ($entry): mixed => $entry->value(),
            iterator_to_array($store->entries($scope), false),
        );
        sort($values);

        self::assertSame(['own'], $values);
    }

    #[DataProvider('collationLookalikeNames')]
    public function testClearTagDistinguishesCaseAndUnicode(string $target, string $neighbour): void
    {
        $store = $this->requireCapability(TaggableStore::class);

        $this->store->set(Key::named('own'), 'own', Ttl::forever());
        $this->store->set(Key::named('other'), 'other', Ttl::forever());
        $store->tag(Key::named('own'), $target);
        $store->tag(Key::named('other'), $neighbour);

        self::assertSame(1, $store->clearTag($target));
        self::assertTrue($this->store->get(Key::named('own'))->isMiss());
        self::assertSame('other', $this->store->get(Key::named('other'))->value());
    }

    public function testTaggingGroupsKeysAndClearTagRemovesThem(): void
    {
        $store = $this->requireCapability(TaggableStore::class);

        $this->store->set(Key::named('post:1'), 'a', Ttl::forever());
        $this->store->set(Key::named('post:2'), 'b', Ttl::forever());
        $this->store->set(Key::named('page:1'), 'c', Ttl::forever());

        $store->tag(Key::named('post:1'), 'posts');
        $store->tag(Key::named('post:2'), 'posts');

        self::assertSame(2, $store->clearTag('posts'));
        self::assertTrue($this->store->get(Key::named('post:1'))->isMiss());
        self::assertTrue($this->store->get(Key::named('post:2'))->isMiss());
        self::assertSame('c', $this->store->get(Key::named('page:1'))->value());
    }

    public function testAtomicIncrementAndCompareAndSwap(): void
    {
        $store = $this->requireCapability(AtomicStore::class);
        $counter = Key::named('counter');

        self::assertSame(1, $store->increment($counter));
        self::assertSame(6, $store->increment($counter, 5));
        self::assertSame(10, $store->increment(Key::named('seeded'), 1, 9));

        self::assertFalse($store->compareAndSwap($counter, 999, 0));
        self::assertTrue($store->compareAndSwap($counter, 6, 42));
        self::assertSame(42, $this->store->get($counter)->value());
    }

    public function testLockingIsMutuallyExclusiveAndReleasable(): void
    {
        $store = $this->requireCapability(LockingStore::class);

        $lock = $store->lock('job', Ttl::seconds(30));
        $rival = $store->lock('job', Ttl::seconds(30));

        self::assertTrue($lock->acquire());
        self::assertFalse($rival->acquire());

        self::assertTrue($lock->release());
        self::assertTrue($rival->acquire());
        self::assertTrue($rival->release());
    }

    public function testLockNamesAreExactAndMayBeLong(): void
    {
        $store = $this->requireCapability(LockingStore::class);
        $long = str_repeat('l', 300);

        // Names differing only by case or accent are different locks.
        $upper = $store->lock('Report', Ttl::seconds(30));
        $lower = $store->lock('report', Ttl::seconds(30));
        $accent = $store->lock('relatório', Ttl::seconds(30));
        $plain = $store->lock('relatorio', Ttl::seconds(30));
        self::assertTrue($upper->acquire());
        self::assertTrue($lower->acquire());
        self::assertTrue($accent->acquire());
        self::assertTrue($plain->acquire());

        // A long name is still one acquirable, exclusive lock.
        $lock = $store->lock($long, Ttl::seconds(30));
        self::assertTrue($lock->acquire());
        self::assertFalse($store->lock($long, Ttl::seconds(30))->acquire());
        self::assertTrue($store->lock(substr($long, 0, 299), Ttl::seconds(30))->acquire());

        foreach ([$upper, $lower, $accent, $plain, $lock] as $held) {
            self::assertTrue($held->release());
        }
    }

    public function testReleaseFreesALockThatWasAcquiredTwice(): void
    {
        $store = $this->requireCapability(LockingStore::class);

        $lock = $store->lock('job', Ttl::seconds(30));
        $rival = $store->lock('job', Ttl::seconds(30));

        self::assertTrue($lock->acquire());
        $lock->acquire(); // re-entrant or not, a repeat must not lose ownership

        self::assertTrue($lock->release());
        self::assertTrue($rival->acquire(), 'A repeated acquire() must not leave the lock stuck.');
        self::assertTrue($rival->release());
    }

    public function testAnExpiredLeaseCannotReleaseItsReplacement(): void
    {
        $store = $this->requireCapability(LockingStore::class);

        $old = $store->lock('job', Ttl::seconds(10));
        self::assertTrue($old->acquire());

        $this->expireLease('job', Ttl::seconds(10));

        $replacement = $store->lock('job', Ttl::seconds(10));
        self::assertTrue($replacement->acquire());

        self::assertFalse($old->release(), 'The old owner must not release the replacement lease.');
        self::assertFalse($store->lock('job', Ttl::seconds(10))->acquire());
        self::assertTrue($replacement->release());
    }

    public function testAFailedAtomicOperationReleasesItsGuard(): void
    {
        $store = $this->requireCapability(AtomicStore::class);
        $key = Key::named('counter');

        $this->store->set($key, 'not a number', Ttl::forever());

        try {
            $store->increment($key);
            self::fail('Incrementing a non-integer must fail.');
        } catch (\Throwable) {
        }

        self::assertTrue($store->compareAndSwap($key, 'not a number', 1), 'The guard must be released after a failure.');
        self::assertSame(2, $store->increment($key));
    }

    public function testAtomicOperationsDoNotRunWhileAnotherWorkerHoldsTheGuard(): void
    {
        $store = $this->requireCapability(AtomicStore::class);
        $key = Key::named('counter');

        $this->store->set($key, 1, Ttl::forever());
        $release = $this->holdAtomicGuard($key);

        foreach ([
            'increment'      => static fn (): mixed => $store->increment($key),
            'compareAndSwap' => static fn (): mixed => $store->compareAndSwap($key, 1, 5),
        ] as $operation => $attempt) {
            try {
                $attempt();
                self::fail(sprintf('%s() must not succeed while the guard is held.', $operation));
            } catch (StoreOperationFailedException $exception) {
                self::assertSame($operation, $exception->operation);
            }
        }

        self::assertSame(1, $this->store->get($key)->value(), 'A timed-out operation must not mutate the entry.');

        $release();
        self::assertSame(2, $store->increment($key));
    }

    /**
     * Make a held lease expire as the backend would let it. Stores whose leases
     * expire by the injected clock need nothing more than the default.
     */
    protected function expireLease(string $name, Ttl $ttl): void
    {
        $this->clock->advance((float) $ttl->inSeconds() + 1);
    }

    /**
     * Hold, as a second worker would, the guard this store takes around one
     * key's atomic read-modify-write; return a callable that releases it.
     * Stores that guard atomic operations differently (in-process state, row
     * locks) have no separate guard to hold, so the default skips.
     *
     * @return callable(): void
     */
    protected function holdAtomicGuard(Key $key): callable
    {
        self::markTestSkipped(sprintf('%s has no separately held atomic guard.', $this->store::class));
    }

    /**
     * @template T of object
     * @param class-string<T> $capability
     * @return T&Store
     */
    private function requireCapability(string $capability): object
    {
        if (!$this->store instanceof $capability) {
            self::markTestSkipped(sprintf('%s does not implement %s.', $this->store::class, $capability));
        }

        /** @var T&Store $store */
        $store = $this->store;

        return $store;
    }
}
