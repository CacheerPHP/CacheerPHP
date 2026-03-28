<?php

use PHPUnit\Framework\TestCase;
use Silviooosilva\CacheerPhp\Cacheer;

class FileCacheStoreFeatureTest extends TestCase
{
    
    /**
     * @var Cacheer
     */
    private $cache;
    
    /**
     * @var string
     */
    private $cacheDir;

    protected function setUp(): void
    {
        $this->cacheDir = __DIR__ . '/cache';
        if (!file_exists($this->cacheDir) || !is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0755, true);
        }
        $this->cache = new Cacheer(['cacheDir' => $this->cacheDir]);
    }

    protected function tearDown(): void
    {
        $this->cache->flushCache();
    }

    public function testAutoFlush()
    {
        $options = [
            'cacheDir' => $this->cacheDir,
            'flushAfter' => '10 seconds'
        ];

        $this->cache = new Cacheer($options);
        $this->cache->putCache('test_key', 'test_data');

        $this->assertEquals('test_data', $this->cache->getCache('test_key'));
        $this->assertTrue($this->cache->isSuccess());

        sleep(11);

        $this->cache = new Cacheer($options);
        $this->cache->getCache('test_key');
        $this->assertFalse($this->cache->isSuccess());
    }

    public function testDataOutputShouldBeOfTypeJson()
    {
        $this->cache = new Cacheer(['cacheDir' => $this->cacheDir], true);
        $this->cache->putCache('key_json', 'data_json');
        $output = $this->cache->getCache('key_json')->toJson();
        $this->assertJson($output);
    }

    public function testDataOutputShouldBeOfTypeArray()
    {
        $this->cache = new Cacheer(['cacheDir' => $this->cacheDir], true);
        $this->cache->putCache('key_array', 'data_array');
        $output = $this->cache->getCache('key_array')->toArray();
        $this->assertIsArray($output);
    }

    public function testDataOutputShouldBeOfTypeObject()
    {
        $this->cache = new Cacheer(['cacheDir' => $this->cacheDir], true);
        $this->cache->putCache('key_object', ['id' => 123]);
        $output = $this->cache->getCache('key_object')->toObject();
        $this->assertIsObject($output);
    }

    public function testPutMany()
    {
        $cacheer = new Cacheer(['cacheDir' => __DIR__ . '/cache']);
        $items = [
            [
                'cacheKey' => 'user_1_profile',
                'cacheData' => ['name' => 'John Doe', 'email' => 'john@example.com']
            ],
            [
                'cacheKey' => 'user_2_profile',
                'cacheData' => ['name' => 'Jane Doe', 'email' => 'jane@example.com']
            ],
        ];

        $cacheer->putMany($items);

        foreach ($items as $item) {
            $this->assertEquals($item['cacheData'], $cacheer->getCache($item['cacheKey']));
        }
    }

    public function test_remember_saves_and_recover_values()
    {
        $this->cache->flushCache();
        $value = $this->cache->remember('remember_test_key', 60, function () {
            return 'valor_teste';
        });
        $this->assertEquals('valor_teste', $value);
        $cachedValue = $this->cache->remember('remember_test_key', 60, function(){
            return 'novo_valor';
        });
        $this->assertEquals('valor_teste', $cachedValue);
    }

    public function test_remember_forever_saves_value_indefinitely()
    {
        $this->cache->flushCache();
        $value = $this->cache->rememberForever('remember_forever_key', function () {
            return 'valor_eterno';
        });
        $this->assertEquals('valor_eterno', $value);
        $cachedValue = $this->cache->rememberForever('remember_forever_key', function () {
            return 'novo_valor';
        });
        $this->assertEquals('valor_eterno', $cachedValue);
    }

    public function test_get_and_forget()
    {
        $this->cache->putCache('cache_key_test', 10);
        $data = $this->cache->getAndForget('cache_key_test');
        $this->assertEquals(10, $data);
        $this->assertNull($this->cache->getAndForget('cache_key_test'));
    }

    public function test_store_if_not_present_with_add_function()
    {
        $this->cache->putCache('cache_key_test', 'existent_data');
        // add() returns false when the key already exists (nothing was written).
        $this->assertFalse($this->cache->add('cache_key_test', 100));
        // add() returns true when the key does not exist yet.
        $this->assertTrue($this->cache->add('non_existent_key', 'non_existent_data'));
    }

    public function test_increment_and_decrement_functions()
    {
        $this->cache->putCache('num_key', 2025);
        $this->cache->increment('num_key', 2);
        $this->assertEquals(2027, $this->cache->getCache('num_key'));
        $this->cache->decrement('num_key', 2);
        $this->assertEquals(2025, $this->cache->getCache('num_key'));
    }

    public function test_get_many_cache_items()
    {
        $items = ['key1' => 'value1', 'key2' => 'value2', 'key3' => 'value3'];
        foreach ($items as $k => $v) { $this->cache->putCache($k, $v); }
        $retrieved = $this->cache->getMany(array_keys($items));
        $this->assertEquals($items, $retrieved);
    }

    public function test_get_all_cache_items()
    {
        $items = ['key1' => 'value1', 'key2' => 'value2', 'key3' => 'value3'];
        foreach ($items as $k => $v) { $this->cache->putCache($k, $v); }
        $retrieved = $this->cache->getAll();
        $this->assertCount(3, $retrieved);
    }
}
