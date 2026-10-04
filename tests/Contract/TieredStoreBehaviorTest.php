<?php

declare(strict_types=1);

namespace Tests\Contract;

use PHPUnit\Framework\TestCase;
use Silviooosilva\CacheerPhp\Cacheer;
use Silviooosilva\CacheerPhp\Kernel\Key;
use Silviooosilva\CacheerPhp\Kernel\Ttl;
use Silviooosilva\CacheerPhp\Stores\ArrayStore;
use Silviooosilva\CacheerPhp\Stores\TieredStore;
use Silviooosilva\CacheerPhp\Support\AfterResponseDeferredExecutor;
use Tests\Support\FakeClock;

final class TieredStoreBehaviorTest extends TestCase
{
    private FakeClock $clock;

    private ArrayStore $l1;

    private ArrayStore $l2;

    private TieredStore $tiered;

    protected function setUp(): void
    {
        $this->clock = new FakeClock();
        $this->l1 = new ArrayStore($this->clock);
        $this->l2 = new ArrayStore($this->clock);
        $this->tiered = new TieredStore($this->l1, $this->l2, $this->clock);
    }

    public function testAnL2HitIsPromotedIntoL1(): void
    {
        $key = Key::named('promote-me');
        $this->l2->set($key, 'shared', Ttl::forever());

        self::assertTrue($this->l1->get($key)->isMiss(), 'Precondition: value only in L2.');

        self::assertSame('shared', $this->tiered->get($key)->value());

        $this->l2->delete($key);
        self::assertSame('shared', $this->tiered->get($key)->value(), 'A read should promote the value into L1.');
    }

    public function testWritesGoThroughToBothLayers(): void
    {
        $key = Key::named('through');
        $this->tiered->set($key, 42, Ttl::forever());

        self::assertSame(42, $this->l2->get($key)->value());
        $this->l2->delete($key);
        self::assertSame(42, $this->tiered->get($key)->value(), 'The write must also land in L1.');
    }

    public function testPromotionTtlIsCappedForL1(): void
    {
        $tiered = new TieredStore($this->l1, $this->l2, $this->clock, Ttl::seconds(30));
        $key = Key::named('capped');

        $tiered->set($key, 'value', Ttl::seconds(3600));

        // L1 copy expires after the 30s cap, even though L2 keeps it for an hour.
        $this->clock->advance(31);
        self::assertTrue($this->l1->get($key)->isMiss());
        self::assertTrue($this->l2->get($key)->isHit());

        // A read repromotes it into L1 from the still-valid L2 copy.
        self::assertSame('value', $tiered->get($key)->value());
        self::assertTrue($this->l1->get($key)->isHit());
    }

    public function testGenerationTokenInvalidatesAnotherWorkersLocalL1(): void
    {
        $sharedL2 = new ArrayStore($this->clock);
        $workerA = new TieredStore(new ArrayStore($this->clock), $sharedL2, $this->clock, generationCheckSeconds: 5.0);
        $workerBLocal = new ArrayStore($this->clock);
        $workerB = new TieredStore($workerBLocal, $sharedL2, $this->clock, generationCheckSeconds: 5.0);

        $key = Key::named('coherent');
        $workerA->set($key, 'v1', Ttl::forever());

        // Worker B reads it (populating its own L1) ...
        self::assertSame('v1', $workerB->get($key)->value());
        self::assertTrue($workerBLocal->get($key)->isHit());

        // ... then worker A clears the whole cache (bumping the generation).
        $workerA->clear();

        // Before the check window elapses, B still trusts its stale L1 copy.
        $this->clock->advance(1);
        self::assertTrue($workerBLocal->get($key)->isHit());

        // Once the window passes, B notices the generation moved and flushes L1.
        $this->clock->advance(5);
        self::assertTrue($workerB->get($key)->isMiss());
        self::assertTrue($workerBLocal->get($key)->isMiss());
    }

    public function testClearTagInvalidatesLocalL1(): void
    {
        $postKey = Key::named('post:1');
        $this->tiered->set($postKey, 'body', Ttl::forever());
        $this->tiered->tag($postKey, 'posts');

        self::assertTrue($this->l1->get($postKey)->isHit());

        self::assertSame(1, $this->tiered->clearTag('posts'));
        self::assertTrue($this->l1->get($postKey)->isMiss());
        self::assertTrue($this->tiered->get($postKey)->isMiss());
    }

    public function testPromotionKeepsTheOriginalCreationTimeAndExpiry(): void
    {
        $key = Key::named('aged');
        $writtenAt = $this->clock->now();
        $this->l2->set($key, 'value', Ttl::seconds(100));

        $this->clock->advance(40);

        foreach (['promoting read' => $this->tiered->get($key), 'L1 hit' => $this->tiered->get($key)] as $read => $entry) {
            self::assertSame($writtenAt, $entry->createdAt(), sprintf('%s must keep the original creation time.', $read));
            self::assertSame($writtenAt + 100, $entry->expiresAt(), sprintf('%s must keep the absolute expiry.', $read));
        }
    }

    public function testACappedL1CopyReportsTheValuesTrueExpiry(): void
    {
        $tiered = new TieredStore($this->l1, $this->l2, $this->clock, Ttl::seconds(30));
        $key = Key::named('capped-expiry');
        $writtenAt = $this->clock->now();

        $tiered->set($key, 'value', Ttl::seconds(3600));
        $this->clock->advance(5);

        $entry = $tiered->get($key);
        self::assertSame($writtenAt, $entry->createdAt());
        self::assertSame($writtenAt + 3600, $entry->expiresAt(), 'The L1 cap is local; the value lives for an hour.');
    }

    public function testFlexibleFreshnessDoesNotRestartWhenAnotherWorkerPromotes(): void
    {
        $shared = new ArrayStore($this->clock);
        $executor = new AfterResponseDeferredExecutor();
        $workerA = new Cacheer(new TieredStore(new ArrayStore($this->clock), $shared, $this->clock), $this->clock);
        $workerB = new Cacheer(new TieredStore(new ArrayStore($this->clock), $shared, $this->clock), $this->clock, $executor);

        $calls = 0;
        $factory = static function () use (&$calls): string {
            return 'value-' . ++$calls;
        };

        self::assertSame('value-1', $workerA->flexible('report', 30, 300, $factory));

        // At 20s worker B reads the still-fresh value, promoting it into B's L1.
        $this->clock->advance(20);
        self::assertSame('value-1', $workerB->flexible('report', 30, 300, $factory));
        self::assertSame(0, $executor->pending());

        // At 35s the value is 35s old — past its 30s fresh window — even though
        // it reached B's L1 only 15s ago. B must serve it stale and refresh.
        $this->clock->advance(15);
        self::assertSame('value-1', $workerB->flexible('report', 30, 300, $factory));
        self::assertSame(1, $executor->pending(), 'Promotion must not restart a value\'s freshness.');
    }
}
