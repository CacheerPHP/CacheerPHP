<?php

use PHPUnit\Framework\TestCase;
use Silviooosilva\CacheerPhp\Cacheer;
use Tests\Support\FakeClock;

/**
 * Verifies the optional $default and $ttl parameters on increment() / decrement().
 *
 * Two distinct paths must hold:
 *
 *   - Legacy path (default omitted or null): missing keys must return false
 *     and the cache must not be written. `bool` return type preserved.
 *
 *   - Create-on-miss path (default provided): missing keys must be initialised
 *     to ($default + $amount), honouring the optional TTL.
 *
 * Falsy stored values (notably 0) must continue to be treated as cache hits.
 */
class IncrementWithDefaultTest extends TestCase
{
    private Cacheer $cache;
    private FakeClock $clock;

    protected function setUp(): void
    {
        $this->clock = new FakeClock();
        $this->cache = new Cacheer(['clock' => $this->clock]);
        $this->cache->setDriver()->useArrayDriver();
    }

    // -------------------------------------------------------------------------
    // Legacy path — no $default provided
    // -------------------------------------------------------------------------

    public function testLegacyIncrementOnExistingKeyReturnsTrue(): void
    {
        $this->cache->putCache('counter', 5);
        $this->assertTrue($this->cache->increment('counter', 2));
        $this->assertSame(7, $this->cache->getCache('counter'));
    }

    public function testLegacyIncrementOnMissingKeyReturnsFalse(): void
    {
        $this->assertFalse($this->cache->increment('absent'));
        $this->assertFalse($this->cache->has('absent'));
    }

    public function testLegacyDecrementOnExistingKeyReturnsTrue(): void
    {
        $this->cache->putCache('counter', 10);
        $this->assertTrue($this->cache->decrement('counter', 3));
        $this->assertSame(7, $this->cache->getCache('counter'));
    }

    public function testLegacyDecrementOnMissingKeyReturnsFalse(): void
    {
        $this->assertFalse($this->cache->decrement('absent'));
        $this->assertFalse($this->cache->has('absent'));
    }

    public function testIncrementHandlesZeroValueCorrectly(): void
    {
        $this->cache->putCache('zero', 0);
        $this->assertTrue($this->cache->increment('zero', 1));
        $this->assertSame(1, $this->cache->getCache('zero'));
    }

    // -------------------------------------------------------------------------
    // Create-on-miss path — $default provided
    // -------------------------------------------------------------------------

    public function testIncrementWithDefaultCreatesMissingKey(): void
    {
        $this->assertTrue($this->cache->increment('hits', 1, '', 0));
        $this->assertSame(1, $this->cache->getCache('hits'));
    }

    public function testIncrementUsesProvidedDefault(): void
    {
        // budget missing → 100 (default) + 10 (amount) = 110
        $this->assertTrue($this->cache->increment('budget', 10, '', 100));
        $this->assertSame(110, $this->cache->getCache('budget'));
    }

    public function testDecrementWithDefaultCreatesMissingKey(): void
    {
        // losses missing → 0 (default) - 1 (amount) = -1
        $this->assertTrue($this->cache->decrement('losses', 1, '', 0));
        $this->assertSame(-1, $this->cache->getCache('losses'));
    }

    public function testDecrementUsesProvidedDefault(): void
    {
        // stock missing → 100 (default) - 5 (amount) = 95
        $this->assertTrue($this->cache->decrement('stock', 5, '', 100));
        $this->assertSame(95, $this->cache->getCache('stock'));
    }

    public function testIncrementWithDefaultZeroExplicit(): void
    {
        // Important: $default = 0 is NOT null — must trigger create-on-miss.
        $this->assertTrue($this->cache->increment('clicks', 3, '', 0));
        $this->assertSame(3, $this->cache->getCache('clicks'));
    }

    public function testIncrementOnExistingKeyIgnoresDefault(): void
    {
        $this->cache->putCache('counter', 50);
        // Default must NOT be applied when the key already exists.
        $this->assertTrue($this->cache->increment('counter', 5, '', 999));
        $this->assertSame(55, $this->cache->getCache('counter'));
    }

    public function testIncrementWithTtl(): void
    {
        // Create-on-miss with a 1-second TTL — TTL must be honoured for the
        // newly-created entry.
        $this->assertTrue($this->cache->increment('rate', 1, '', 0, 1));
        $this->assertSame(1, $this->cache->getCache('rate'));

        $this->clock->advance(2);
        $this->assertFalse($this->cache->has('rate'));
    }
}
