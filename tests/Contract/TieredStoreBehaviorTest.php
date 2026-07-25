<?php

declare(strict_types=1);

namespace Tests\Contract;

use PHPUnit\Framework\TestCase;
use Silviooosilva\CacheerPhp\Kernel\Key;
use Silviooosilva\CacheerPhp\Kernel\Ttl;
use Silviooosilva\CacheerPhp\Stores\ArrayStore;
use Silviooosilva\CacheerPhp\Stores\TieredStore;
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
        self::assertSame('shared', $this->l1->get($key)->value(), 'A read should promote the value into L1.');
    }

    public function testWritesGoThroughToBothLayers(): void
    {
        $key = Key::named('through');
        $this->tiered->set($key, 42, Ttl::forever());

        self::assertSame(42, $this->l1->get($key)->value());
        self::assertSame(42, $this->l2->get($key)->value());
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
}
