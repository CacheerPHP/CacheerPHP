<?php

use PHPUnit\Framework\TestCase;
use Silviooosilva\CacheerPhp\Cacheer;
use Silviooosilva\CacheerPhp\Core\Connect;

class DatabaseCacheStoreTest extends TestCase
{
    private $cache;

    protected function setUp(): void
    {
        $this->cache = new Cacheer();
        $this->cache->setConfig()->setDatabaseConnection(Connect::getInstance()->getAttribute(PDO::ATTR_DRIVER_NAME));
        $this->cache->setDriver()->useDatabaseDriver();
        $this->cache->setConfig()->setTimeZone('America/Toronto');
    }

    protected function tearDown(): void
    {
        $this->cache->flushCache();
    }

    public function testPutCacheInDatabase()
    {
        $cacheKey = 'test_key';
        $cacheData = ['name' => 'John Doe', 'email' => 'john@example.com'];

        $this->cache->putCache($cacheKey, $cacheData, '', 3600);

        $result = $this->cache->getCache($cacheKey);

        $this->assertNotEmpty($result);
        $this->assertEquals($cacheData, $result);
    }

    public function testGetCacheFromDatabase()
    {
        $cacheKey = 'test_key02';
        $cacheData = ['name' => 'Jane Doe', 'email' => 'jane@example.com'];

        $this->cache->putCache($cacheKey, $cacheData, '', 3600);
        $this->assertEquals("Cache Stored Successfully", $this->cache->getMessage());

        $result = $this->cache->getCache($cacheKey);

        $this->assertNotEmpty($result);
        $this->assertEquals($cacheData, $result);
    }

    public function testExpiredCacheInDatabase()
    {
        $cacheKey = 'expired_key';
        $cacheData = ['name' => 'Expired User', 'email' => 'expired@example.com'];

        $this->cache->putCache($cacheKey, $cacheData, '', -3600);
        $this->assertEquals("Cache Stored Successfully", $this->cache->getMessage());

        $this->assertEmpty($this->cache->getCache($cacheKey));
        $this->assertFalse($this->cache->isSuccess());
    }
    public function testOverwriteExistingCacheInDatabase()
    {

        $cacheKey = 'overwrite_key';
        $initialCacheData = ['name' => 'Initial Data', 'email' => 'initial@example.com'];
        $newCacheData = ['name' => 'New Data', 'email' => 'new@example.com'];

        $expirationTime = date('Y-m-d H:i:s', time() + 3600);


        $db = Connect::getInstance();
        $query = $db->prepare("INSERT INTO cacheer_table (cacheKey, cacheData, cacheNamespace, expirationTime) VALUES (?, ?, ?, ?)");
        $query->bindValue(1, $cacheKey);
        $query->bindValue(2, serialize($initialCacheData));
        $query->bindValue(3, '');
        $query->bindValue(4, $expirationTime);

        $this->assertTrue($query->execute());

        $this->cache->appendCache($cacheKey, $newCacheData);
        $this->assertEquals("Cache updated successfully.", $this->cache->getMessage());

        $driver = Connect::getInstance()->getAttribute(PDO::ATTR_DRIVER_NAME);
        $nowFunction = ($driver === 'sqlite') ? "DATETIME('now', 'localtime')" : "NOW()";

        $query = $db->prepare("SELECT cacheData FROM cacheer_table WHERE cacheKey = ? AND cacheNamespace = ? AND expirationTime > $nowFunction");
        $query->bindValue(1, $cacheKey);
        $query->bindValue(2, '');

        $this->assertTrue($query->execute());

        $result = $query->fetch(PDO::FETCH_ASSOC);

        $this->assertEquals($newCacheData, unserialize($result['cacheData']));
    }

    public function testPutManyCacheItemsInDatabase(): void
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

    public function testAppendCacheWithNamespaceInDatabase(): void
    {
        $cacheKey = 'test_append_key_ns';
        $namespace = 'test_namespace';

        $initialData = ['initial' => 'data'];
        $additionalData = ['new' => 'data'];

        $expectedData = array_merge($initialData, $additionalData);

        // Armazena os dados iniciais no cache com namespace
        $this->cache->putCache($cacheKey, $initialData, $namespace);
        $this->assertTrue($this->cache->isSuccess());

        // Adiciona novos dados ao cache existente com namespace
        $this->cache->appendCache($cacheKey, $additionalData, $namespace);
        $this->assertTrue($this->cache->isSuccess());

        // Verifica se os dados no cache são os esperados
        $cachedData = $this->cache->getCache($cacheKey, $namespace);
        $this->assertEquals($expectedData, $cachedData);
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


    public function testClearCacheDataFromDatabase(): void
    {
        $cacheKey = 'test_key';
        $data = 'test_data';

        $this->cache->putCache($cacheKey, $data);
        $this->assertEquals("Cache Stored Successfully", $this->cache->getMessage());

        $this->cache->clearCache($cacheKey);
        $this->assertTrue($this->cache->isSuccess());
        $this->assertEquals("Cache deleted successfully!", $this->cache->getMessage());

        $this->assertEmpty($this->cache->getCache($cacheKey));
    }


    public function testFlushCacheDataFromDatabase(): void
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
        $this->assertEquals("Flush finished successfully", $this->cache->getMessage());
    }

    public function test_remember_saves_and_recover_values()
    {
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

        $addExisting = $this->cache->add($existentKey, 100);
        $this->assertFalse($addExisting);
        $this->assertEquals('existent_data', $this->cache->getCache($existentKey));

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
        $this->assertCount(3, $retrievedItems);
        $this->assertEquals($cacheItems, $retrievedItems);
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

    public function test_tag_and_flush_tag_in_database_driver()
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

    public function test_tag_with_namespace_and_flush_tag_in_database_driver()
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
