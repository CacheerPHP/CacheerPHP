<?php

use PHPUnit\Framework\TestCase;
use Silviooosilva\CacheerPhp\Cacheer;

class BooleanReturnTest extends TestCase
{
    private Cacheer $cache;

    protected function setUp(): void
    {
        $this->cache = new Cacheer();
        $this->cache->setDriver()->useArrayDriver();
    }

    protected function tearDown(): void
    {
        $this->cache->flushCache();
    }

    public function testHasReturnsBoolean(): void
    {
        $this->cache->putCache('bool_key', 'value');
        $this->assertTrue($this->cache->has('bool_key'));
        $this->assertTrue($this->cache->isSuccess());

        $this->assertFalse($this->cache->has('unknown_key'));
        $this->assertFalse($this->cache->isSuccess());
    }

    public function testMutatingMethodsReturnBoolean(): void
    {
        $this->assertTrue($this->cache->putCache('k', 'v'));
        $this->assertTrue($this->cache->flushCache());
        $this->assertTrue($this->cache->putCache('k', 'v'));
        $this->assertTrue($this->cache->clearCache('k'));
    }

    public function testAddReturnsFalseWhenKeyExists(): void
    {
        $this->cache->putCache('existing_key', 'original');
        $this->assertTrue($this->cache->isSuccess());

        $result = $this->cache->add('existing_key', 'new_value');

        $this->assertFalse($result, 'add() must return false when the key already exists.');
        $this->assertEquals('original', $this->cache->getCache('existing_key'));
    }

    public function testAddReturnsTrueWhenKeyNotExists(): void
    {
        $result = $this->cache->add('fresh_key', 'stored_value');

        $this->assertTrue($result, 'add() must return true when the key does not exist.');
        $this->assertEquals('stored_value', $this->cache->getCache('fresh_key'));
        $this->assertTrue($this->cache->isSuccess());
    }
}
