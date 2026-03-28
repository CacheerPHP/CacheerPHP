<?php

use PHPUnit\Framework\TestCase;
use Silviooosilva\CacheerPhp\Cacheer;
use Silviooosilva\CacheerPhp\CacheStore\RedisCacheStore;

class RedisCacheStoreTest extends TestCase
{

  /** @var Cacheer */
  private $cache;

  protected function setUp(): void
  {
    $this->cache = new Cacheer();
    $this->cache->setDriver()->useRedisDriver();
  }

  protected function tearDown(): void
  {
    $this->cache->flushCache();
  }

  public function testUsingRedisDriverSetsProperInstance()
  {
    $this->assertInstanceOf(RedisCacheStore::class, $this->cache->getCacheStore());
  }

  public function testPutCacheInRedis()
  {
    $cacheKey = 'test_key';
    $cacheData = ['name' => 'Sílvio Silva', 'role' => 'Developer'];

    $this->cache->putCache($cacheKey, $cacheData);

    $this->assertEquals("Cache stored successfully", $this->cache->getMessage());
    $this->assertNotEmpty($this->cache->getCache($cacheKey));
    $this->assertEquals($cacheData, $this->cache->getCache($cacheKey));

  }

  public function testGetCacheFromRedis()
  {
    $cacheKey = 'test_key';
    $cacheData = ['name' => 'Sílvio Silva', 'role' => 'Developer'];

    $this->cache->putCache($cacheKey, $cacheData);
    
    $this->assertEquals("Cache stored successfully", $this->cache->getMessage());

    $data = $this->cache->getCache($cacheKey);
    $this->assertNotEmpty($data);
    $this->assertEquals($cacheData, $data);
  }

  public function testExpiredCacheInRedis()
  {
    $cacheKey = 'expired_key';
    $cacheData = ['name' => 'Expired User', 'email' => 'expired@example.com'];

    $this->cache->putCache($cacheKey, $cacheData, '', 1);
    sleep(3);

    $this->assertEquals("Cache stored successfully", $this->cache->getMessage());
    $this->assertEmpty($this->cache->getCache($cacheKey));
    $this->assertFalse($this->cache->isSuccess());
  }

  public function testOverwriteExistingCacheInRedis()
  {
    $cacheKey = 'overwrite_key';
    $initialCacheData = ['name' => 'Initial Data', 'email' => 'initial@example.com'];
    $newCacheData = ['name' => 'New Data', 'email' => 'new@example.com'];

    $this->cache->putCache($cacheKey, $initialCacheData);
    $this->assertEquals("Cache stored successfully", $this->cache->getMessage());

    $this->cache->appendCache($cacheKey, $newCacheData);
    $this->assertEquals("Cache appended successfully", $this->cache->getMessage());
    $this->assertEquals($newCacheData, $this->cache->getCache($cacheKey));
  }

  public function testPutManyCacheItemsInRedis()
  {
     $items = [
            [
                'cacheKey' => 'user_1_profile',
                'cacheData' => [
                    ['name' => 'John Doe', 'email' => 'john@example.com'],
                    ['name' => 'John Doe', 'email' => 'john@example.com'],
                    ['name' => 'John Doe', 'email' => 'john@example.com'],
                    ['name' => 'John Doe', 'email' => 'john@example.com']
                ]
            ],
            [
                'cacheKey' => 'user_2_profile',
                'cacheData' => [
                    ['name' => 'Jane Doe', 'email' => 'jane@example.com'],
                    ['name' => 'Jane Doe', 'email' => 'jane@example.com'],
                    ['name' => 'Jane Doe', 'email' => 'jane@example.com'],
                    ['name' => 'Jane Doe', 'email' => 'jane@example.com']
                ]
            ]
        ];

    $this->cache->putMany($items);
    foreach ($items as $item) {
            $this->assertEquals($item['cacheData'], $this->cache->getCache($item['cacheKey']));
        }
  }

      public function testAppendCacheWithNamespaceInRedis()
    {
        $cacheKey = 'test_append_key_ns';
        $namespace = 'test_namespace';

        $initialData = ['initial' => 'data'];
        $additionalData = ['new' => 'data'];

        $expectedData = array_merge($initialData, $additionalData);

 
        $this->cache->putCache($cacheKey, $initialData, $namespace);
        $this->assertTrue($this->cache->isSuccess());

 
        $this->cache->appendCache($cacheKey, $additionalData, $namespace);
        $this->assertTrue($this->cache->isSuccess());


        $cachedData = $this->cache->getCache($cacheKey, $namespace);
        $this->assertEquals($expectedData, $cachedData);
    }

      public function testDataOutputShouldBeOfTypeArray()
    {

        $this->cache->useFormatter();

        $cacheKey = "key_array";
        $cacheData = "data_array";

        $this->cache->putCache($cacheKey, $cacheData);
        $this->assertTrue($this->cache->isSuccess());

        $cacheOutput = $this->cache->getCache($cacheKey)->toArray();
        $this->assertTrue($this->cache->isSuccess());
        $this->assertIsArray($cacheOutput);
    }

    public function testDataOutputShouldBeOfTypeObject()
    {
        $this->cache->useFormatter();

        $cacheKey = "key_object";
        $cacheData = ["id" => 123];

        $this->cache->putCache($cacheKey, $cacheData);
        $this->assertTrue($this->cache->isSuccess());

        $cacheOutput = $this->cache->getCache($cacheKey)->toObject();
        $this->assertTrue($this->cache->isSuccess());
        $this->assertIsObject($cacheOutput);
    }

        public function testDataOutputShouldBeOfTypeJson()
    {
        $this->cache->useFormatter();

        $cacheKey = "key_json";
        $cacheData = "data_json";

        $this->cache->putCache($cacheKey, $cacheData);
        $this->assertTrue($this->cache->isSuccess());

        $cacheOutput = $this->cache->getCache($cacheKey)->toJson();
        $this->assertTrue($this->cache->isSuccess());
        $this->assertJson($cacheOutput);
    }

      public function testClearCacheDataFromRedis()
    {
        $cacheKey = 'test_key';
        $data = 'test_data';

        $this->cache->putCache($cacheKey, $data);
        $this->assertEquals("Cache stored successfully", $this->cache->getMessage());

        $this->cache->clearCache($cacheKey);
        $this->assertTrue($this->cache->isSuccess());
        $this->assertEquals("Cache cleared successfully", $this->cache->getMessage());

        $this->assertEmpty($this->cache->getCache($cacheKey));
    }

  public function testFlushCacheDataFromRedis()
  {
        $key1 = 'test_key1';
        $data1 = 'test_data1';

        $key2 = 'test_key2';
        $data2 = 'test_data2';

        $this->cache->putCache($key1, $data1);
        $this->cache->putCache($key2, $data2);
        $this->assertTrue($this->cache->isSuccess());
        $this->assertTrue($this->cache->isSuccess());

        $this->cache->flushCache();

        $this->assertTrue($this->cache->isSuccess());
        $this->assertEquals("Cache flushed successfully", $this->cache->getMessage());
    }

  public function testHasCacheFromRedis()
  {
    $cacheKey = 'test_key';
    $cacheData = ['name' => 'Sílvio Silva', 'role' => 'Developer'];

    $this->cache->putCache($cacheKey, $cacheData);

    $this->assertEquals("Cache stored successfully", $this->cache->getMessage());
    $this->assertTrue($this->cache->isSuccess());
  }

  public function testRenewCacheFromRedis()
  {
    $cacheKey = 'expired_key';
    $cacheData = ['name' => 'Expired User', 'email' => 'expired@example.com'];

    // Define TTL de 10 seg para que a chave ainda exista quando renovarmos
    $this->cache->putCache($cacheKey, $cacheData, '', 120);
    $this->assertEquals("Cache stored successfully", $this->cache->getMessage());
    sleep(2);

    // Verifica que a chave existe antes de renovar
    $this->assertNotEmpty($this->cache->getCache($cacheKey));

    $this->cache->renewCache($cacheKey, 7200);
    $this->assertTrue($this->cache->isSuccess());
    $this->assertNotEmpty($this->cache->getCache($cacheKey));
  }

    public function testRenewCacheWithNamespaceFromRedis()
  {
    $cacheKey = 'expired_key';
    $namespace = 'expired_namespace';
    $cacheData = ['name' => 'Expired User', 'email' => 'expired@example.com'];

    $this->cache->putCache($cacheKey, $cacheData, $namespace, 120);
    $this->assertEquals("Cache stored successfully", $this->cache->getMessage());
    sleep(2);

    $this->assertNotEmpty($this->cache->getCache($cacheKey, $namespace));

    $this->cache->renewCache($cacheKey, 7200, $namespace);
    $this->assertTrue($this->cache->isSuccess());
    $this->assertNotEmpty($this->cache->getCache($cacheKey, $namespace));
  }

  public function test_remember_saves_and_recover_values() 
    {
        $this->cache->flushCache();

        $value = $this->cache->remember('remember_test_key', 60, function () {
            return 'valor_teste';
        });

        $this->assertEquals('valor_teste', $value);

        $cachedValue = $this->cache->remember('remember_test_key', 60, function (){
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
        $cacheKey = 'cache_key_test';
        $this->cache->putCache($cacheKey, 10);

        $this->assertTrue($this->cache->isSuccess());

        $cacheData = $this->cache->getAndForget($cacheKey);

        $this->assertTrue($this->cache->isSuccess());
        $this->assertEquals(10, $cacheData);

        $oldCacheData = $this->cache->getAndForget($cacheKey);

        $this->assertNull($oldCacheData);
        $this->assertFalse($this->cache->isSuccess());

        $noCacheData = $this->cache->getAndForget('non_existent_cache_key');
        $this->assertNull($noCacheData);
    }

    public function test_store_if_not_present_with_add_function()
    {
        $existentKey    = 'cache_key_test';
        $nonExistentKey = 'non_existent_key';

        $this->cache->putCache($existentKey, 'existent_data');
        $this->assertTrue($this->cache->isSuccess());
        $this->assertEquals('existent_data', $this->cache->getCache($existentKey));

        // add() returns false when the key already exists (nothing was written).
        $addExisting = $this->cache->add($existentKey, 100);
        $this->assertFalse($addExisting);
        $this->assertEquals('existent_data', $this->cache->getCache($existentKey));

        // add() returns true when the key does not exist yet.
        $addNew = $this->cache->add($nonExistentKey, 'non_existent_data');
        $this->assertTrue($addNew);
        $this->assertEquals('non_existent_data', $this->cache->getCache($nonExistentKey));
        $this->assertTrue($this->cache->isSuccess());
    }

      public function test_increment_function() {

        $cacheKey = 'test_increment';
        $cacheData = 2025;

        $this->cache->putCache($cacheKey, $cacheData);

        $this->assertTrue($this->cache->isSuccess());
        $this->assertEquals($cacheData, $this->cache->getCache($cacheKey));
        $this->assertIsNumeric($this->cache->getCache($cacheKey));

        $increment = $this->cache->increment($cacheKey, 2);
        $this->assertTrue($increment);

        $this->assertEquals(2027, $this->cache->getCache($cacheKey));

    }

        public function test_decrement_function() {

        $cacheKey = 'test_decrement';
        $cacheData = 2027;

        $this->cache->putCache($cacheKey, $cacheData);

        $this->assertTrue($this->cache->isSuccess());
        $this->assertEquals($cacheData, $this->cache->getCache($cacheKey));
        $this->assertIsNumeric($this->cache->getCache($cacheKey));

        $increment = $this->cache->decrement($cacheKey, 2);
        $this->assertTrue($increment);

        $this->assertEquals(2025, $this->cache->getCache($cacheKey));

    }

    public function test_get_many_cache_items()
    {
        $this->cache->useFormatter();
        $cacheItems = [
            'key1' => 'value1',
            'key2' => 'value2',
            'key3' => 'value3'
        ];
        foreach ($cacheItems as $key => $value) {
            $this->cache->putCache($key, $value);
        }
        $this->assertTrue($this->cache->isSuccess());
        $retrievedItems = $this->cache->getMany(array_keys($cacheItems));
        $this->assertTrue($this->cache->isSuccess());
        $this->assertCount(3, $retrievedItems->toArray());
        $this->assertEquals($cacheItems, $retrievedItems->toArray());
    }

    public function test_get_all_cache_items()
    {
        $namespace = 'test_namespace';
        $cacheKey = 'test_key';
        $cacheData = ['foo' => 'bar'];

        $this->cache->putCache($cacheKey, $cacheData, $namespace);
        $this->assertTrue($this->cache->isSuccess());
        $retrievedItems = $this->cache->getAll($namespace);
        $this->assertTrue($this->cache->isSuccess());
        $this->assertCount(1, $retrievedItems);
        $this->assertArrayHasKey($cacheKey, $retrievedItems);
        $this->assertEquals($cacheData, $retrievedItems[$cacheKey]);
    }

    public function test_tag_and_flush_tag_in_redis_driver()
    {
        $k1 = 'tag_key_1';
        $k2 = 'tag_key_2';
        $this->cache->putCache($k1, 'v1');
        $this->cache->putCache($k2, 'v2');

        $ok = $this->cache->tag('groupA', $k1, $k2);
        $this->assertTrue($ok);
        $this->assertTrue($this->cache->isSuccess());

        $this->cache->flushTag('groupA');
        $this->assertTrue($this->cache->isSuccess());

        $this->assertEmpty($this->cache->getCache($k1));
        $this->assertEmpty($this->cache->getCache($k2));
    }

    public function test_tag_with_namespace_and_flush_tag_in_redis_driver()
    {
        $ns = 'nsA';
        $k1 = 'k1';
        $k2 = 'k2';
        $this->cache->putCache($k1, 'v1', $ns);
        $this->cache->putCache($k2, 'v2', $ns);

        $ok = $this->cache->tag('groupNS', $ns . ':' . $k1, $ns . ':' . $k2);
        $this->assertTrue($ok);

        $this->cache->flushTag('groupNS');
        $this->assertEmpty($this->cache->getCache($k1, $ns));
        $this->assertEmpty($this->cache->getCache($k2, $ns));
    }

}
