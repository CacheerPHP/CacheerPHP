<?php

declare(strict_types=1);

namespace Tests\Kernel;

use PHPUnit\Framework\TestCase;
use Silviooosilva\CacheerPhp\Contracts\BatchStore;
use Silviooosilva\CacheerPhp\Contracts\FlushableScopeStore;
use Silviooosilva\CacheerPhp\Contracts\InspectableStore;
use Silviooosilva\CacheerPhp\Contracts\PrunableStore;
use Silviooosilva\CacheerPhp\Contracts\Store;
use Silviooosilva\CacheerPhp\Contracts\TouchStore;
use Silviooosilva\CacheerPhp\Kernel\Key;
use Silviooosilva\CacheerPhp\Kernel\Scope;
use Silviooosilva\CacheerPhp\Kernel\Ttl;
use Silviooosilva\CacheerPhp\Stores\ArrayStore;
use Tests\Support\FakeClock;

final class ArrayStoreTest extends TestCase
{
    private FakeClock $clock;

    private ArrayStore $store;

    protected function setUp(): void
    {
        $this->clock = new FakeClock();
        $this->store = new ArrayStore($this->clock);
    }

    public function testItIsTheExecutableBaseAndApplicableCapabilityReference(): void
    {
        self::assertInstanceOf(Store::class, $this->store);
        self::assertInstanceOf(BatchStore::class, $this->store);
        self::assertInstanceOf(TouchStore::class, $this->store);
        self::assertInstanceOf(PrunableStore::class, $this->store);
        self::assertInstanceOf(InspectableStore::class, $this->store);
        self::assertInstanceOf(FlushableScopeStore::class, $this->store);
    }

    public function testHitMissNullAndExpirationSemantics(): void
    {
        $key = Key::named('nullable');

        self::assertTrue($this->store->get($key)->isMiss());

        $this->store->set($key, null, Ttl::seconds(10));
        self::assertTrue($this->store->get($key)->isHit());
        self::assertNull($this->store->get($key)->value());

        $this->clock->advance(10);
        self::assertTrue($this->store->get($key)->isMiss());
    }

    public function testBatchOperationsPreserveOrderAndMisses(): void
    {
        $first = Key::named('first');
        $second = Key::named('second');
        $missing = Key::named('missing');

        $this->store->setMany([
            ['key' => $first, 'value' => 1],
            ['key' => $second, 'value' => null],
        ], Ttl::forever());

        $entries = $this->store->getMany([$second, $missing, $first]);

        self::assertNull($entries[0]->value());
        self::assertTrue($entries[1]->isMiss());
        self::assertSame(1, $entries[2]->value());
        self::assertTrue($this->store->deleteMany([$first, $second]));
        self::assertFalse($this->store->deleteMany([$missing]));
    }

    public function testTouchPruneInspectionAndNestedScopeClearing(): void
    {
        $root = Key::named('root');
        $tenant = Scope::named('tenant');
        $tenantKey = Key::named('user')->within($tenant);
        $nestedKey = Key::named('profile')->within($tenant->child('nested'));
        $expiredKey = Key::named('expired');

        $this->store->set($root, 'root', Ttl::forever());
        $this->store->set($tenantKey, 'tenant', Ttl::seconds(2));
        $this->store->set($nestedKey, 'nested', Ttl::forever());
        $this->store->set($expiredKey, 'expired', Ttl::seconds(1));

        $this->clock->advance(1);
        self::assertSame(1, $this->store->prune());
        self::assertTrue($this->store->touch($tenantKey, Ttl::seconds(10)));
        self::assertCount(2, iterator_to_array($this->store->entries($tenant)));

        $this->store->clearScope($tenant);

        self::assertTrue($this->store->get($tenantKey)->isMiss());
        self::assertTrue($this->store->get($nestedKey)->isMiss());
        self::assertSame('root', $this->store->get($root)->value());
    }
}
