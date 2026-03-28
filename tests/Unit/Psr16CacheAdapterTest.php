<?php

use PHPUnit\Framework\TestCase;
use Silviooosilva\CacheerPhp\Cacheer;
use Silviooosilva\CacheerPhp\Psr\Psr16CacheAdapter;
use Silviooosilva\CacheerPhp\Exceptions\CacheInvalidArgumentException;
use Psr\SimpleCache\CacheInterface;

/**
 * PSR-16 compliance tests for Psr16CacheAdapter.
 *
 * These tests verify that the adapter correctly implements
 * \Psr\SimpleCache\CacheInterface and enforces PSR-16 key rules.
 */
class Psr16CacheAdapterTest extends TestCase
{
    private Psr16CacheAdapter $psr;
    private Cacheer $cache;

    protected function setUp(): void
    {
        $this->cache = new Cacheer();
        $this->cache->setDriver()->useArrayDriver();
        $this->psr   = new Psr16CacheAdapter($this->cache);
    }

    protected function tearDown(): void
    {
        $this->psr->clear();
    }

    // -------------------------------------------------------------------------
    // Interface contract
    // -------------------------------------------------------------------------

    public function testImplementsCacheInterface(): void
    {
        $this->assertInstanceOf(CacheInterface::class, $this->psr);
    }

    // -------------------------------------------------------------------------
    // get / set / has / delete
    // -------------------------------------------------------------------------

    public function testGetReturnsDefaultOnMiss(): void
    {
        $this->assertNull($this->psr->get('missing_key'));
        $this->assertSame('fallback', $this->psr->get('missing_key', 'fallback'));
    }

    public function testSetAndGet(): void
    {
        $this->assertTrue($this->psr->set('greet', 'hello'));
        $this->assertSame('hello', $this->psr->get('greet'));
    }

    public function testSetOverwritesExistingValue(): void
    {
        $this->psr->set('key', 'first');
        $this->psr->set('key', 'second');

        $this->assertSame('second', $this->psr->get('key'));
    }

    public function testDelete(): void
    {
        $this->psr->set('to_delete', 'bye');
        $this->psr->delete('to_delete');

        $this->assertNull($this->psr->get('to_delete'));
    }

    public function testClear(): void
    {
        $this->psr->set('a', 1);
        $this->psr->set('b', 2);
        $this->psr->clear();

        $this->assertNull($this->psr->get('a'));
        $this->assertNull($this->psr->get('b'));
    }

    public function testHas(): void
    {
        $this->assertFalse($this->psr->has('ghost'));

        $this->psr->set('present', true);
        $this->assertTrue($this->psr->has('present'));

        $this->psr->delete('present');
        $this->assertFalse($this->psr->has('present'));
    }

    // -------------------------------------------------------------------------
    // Falsy values must survive the round-trip
    // -------------------------------------------------------------------------

    public function testSetAndGetZero(): void
    {
        $this->psr->set('zero', 0);
        $this->assertSame(0, $this->psr->get('zero', 'default'));
    }

    public function testSetAndGetFalse(): void
    {
        $this->psr->set('falsy', false);
        $this->assertFalse($this->psr->get('falsy', 'default'));
    }

    public function testSetAndGetEmptyString(): void
    {
        $this->psr->set('empty', '');
        $this->assertSame('', $this->psr->get('empty', 'default'));
    }

    // -------------------------------------------------------------------------
    // getMultiple / setMultiple / deleteMultiple
    // -------------------------------------------------------------------------

    public function testGetMultiple(): void
    {
        $this->psr->set('x', 10);
        $this->psr->set('y', 20);

        $results = $this->psr->getMultiple(['x', 'y', 'z'], 0);

        $this->assertSame(10, $results['x']);
        $this->assertSame(20, $results['y']);
        $this->assertSame(0,  $results['z'], 'Missing key must return the $default.');
    }

    public function testSetMultiple(): void
    {
        $ok = $this->psr->setMultiple(['p' => 'apple', 'q' => 'banana']);

        $this->assertTrue($ok);
        $this->assertSame('apple',  $this->psr->get('p'));
        $this->assertSame('banana', $this->psr->get('q'));
    }

    public function testDeleteMultiple(): void
    {
        $this->psr->setMultiple(['r' => 1, 's' => 2, 't' => 3]);

        $this->psr->deleteMultiple(['r', 's']);

        $this->assertNull($this->psr->get('r'));
        $this->assertNull($this->psr->get('s'));
        $this->assertSame(3, $this->psr->get('t'));
    }

    // -------------------------------------------------------------------------
    // TTL handling
    // -------------------------------------------------------------------------

    public function testNullTtlMeansForever(): void
    {
        // Storing with null TTL must not throw and the value must be readable.
        $this->psr->set('eternal', 'value', null);
        $this->assertSame('value', $this->psr->get('eternal'));
    }

    public function testDateIntervalTtl(): void
    {
        $interval = new DateInterval('PT1H'); // 1 hour
        $this->psr->set('timed', 'data', $interval);
        $this->assertSame('data', $this->psr->get('timed'));
    }

    public function testZeroTtlDeletesItem(): void
    {
        $this->psr->set('ephemeral', 'data');
        // A TTL of 0 means "expire immediately".
        $this->psr->set('ephemeral', 'data', 0);

        $this->assertNull($this->psr->get('ephemeral'));
    }

    // -------------------------------------------------------------------------
    // PSR-16 key validation
    // -------------------------------------------------------------------------

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidKeyProvider')]
    public function testInvalidKeyThrowsException(string $key): void
    {
        $this->expectException(CacheInvalidArgumentException::class);
        $this->psr->get($key);
    }

    public static function invalidKeyProvider(): array
    {
        return [
            'empty string'         => [''],
            'curly open'           => ['key{bad'],
            'curly close'          => ['key}bad'],
            'parenthesis open'     => ['key(bad'],
            'parenthesis close'    => ['key)bad'],
            'forward slash'        => ['key/bad'],
            'backslash'            => ['key\\bad'],
            'at sign'              => ['key@bad'],
            'colon'                => ['key:bad'],
        ];
    }

    public function testValidKeyDoesNotThrow(): void
    {
        // Should not throw — dots, dashes, underscores, alphanumerics are all valid.
        $this->assertNull($this->psr->get('valid-key_123.ok'));
    }

    // -------------------------------------------------------------------------
    // Namespace isolation
    // -------------------------------------------------------------------------

    public function testNamespaceIsolatesKeys(): void
    {
        $ns1 = new Psr16CacheAdapter($this->cache, 'ns1');
        $ns2 = new Psr16CacheAdapter($this->cache, 'ns2');

        $ns1->set('shared_key', 'from_ns1');
        $ns2->set('shared_key', 'from_ns2');

        $this->assertSame('from_ns1', $ns1->get('shared_key'));
        $this->assertSame('from_ns2', $ns2->get('shared_key'));
    }
}
