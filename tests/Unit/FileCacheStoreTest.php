<?php

use PHPUnit\Framework\TestCase;
use Silviooosilva\CacheerPhp\Cacheer;
use Silviooosilva\CacheerPhp\Helpers\EnvHelper;
use Silviooosilva\CacheerPhp\Utils\CacheDriver;

class FileCacheStoreTest extends TestCase
{
    private $cache;
    private $cacheDir;

    protected function setUp(): void
    {
        $this->cacheDir = __DIR__ . '/cache';
        if (!file_exists($this->cacheDir) || !is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0755, true);
        }

        $options = [
            'cacheDir' => $this->cacheDir,
        ];

        $this->cache = new Cacheer($options);
    }

    protected function tearDown(): void
    {
        $this->cache->flushCache();
    }

    public function testPutCache()
    {
        $cacheKey = 'test_key';
        $data = 'test_data';

        $this->cache->putCache($cacheKey, $data);
        $this->assertTrue($this->cache->isSuccess());
        $this->assertEquals('Cache file created successfully', $this->cache->getMessage());

        $cacheFile = $this->cacheDir . '/' . md5($cacheKey) . '.cache';
        $this->assertFileExists($cacheFile);
        $this->assertEquals($data, $this->cache->getCache($cacheKey));
    }

    public function testGetCache()
    {
        $cacheKey = 'test_key';
        $data = 'test_data';

        $this->cache->putCache($cacheKey, $data);

        $cachedData = $this->cache->getCache($cacheKey);
        $this->assertTrue($this->cache->isSuccess());
        $this->assertEquals($data, $cachedData);

        // Store with 1-second TTL, wait for it to expire, then verify miss.
        // In v5.0.0 the expiry is stored in the file envelope, so we must use
        // a short TTL at write time (not at read time as in the legacy driver).
        $this->cache->putCache($cacheKey, $data, '', 1);
        sleep(2);
        $this->cache->getCache($cacheKey);
        $this->assertFalse($this->cache->isSuccess());
        $this->assertEquals('cacheFile not found, does not exist or has expired.', $this->cache->getMessage());
    }

    public function testClearCache()
    {
        $cacheKey = 'test_key';
        $data = 'test_data';

        $this->cache->putCache($cacheKey, $data);
        $this->cache->clearCache($cacheKey);

        $this->assertTrue($this->cache->isSuccess());
        $this->assertEquals('Cache file deleted successfully!', $this->cache->getMessage());

        $cacheFile = $this->cacheDir . '/' . md5($cacheKey) . '.cache';
        $this->assertFileDoesNotExist($cacheFile);
    }

    public function testFlushCache()
    {
        $key1 = 'test_key1';
        $data1 = 'test_data1';

        $key2 = 'test_key2';
        $data2 = 'test_data2';

        $this->cache->putCache($key1, $data1);
        $this->cache->putCache($key2, $data2);
        $this->cache->flushCache();

        $cacheFile1 = $this->cacheDir . '/' . md5($key1) . '.cache';
        $cacheFile2 = $this->cacheDir . '/' . md5($key2) . '.cache';

        $this->assertFileDoesNotExist($cacheFile1);
        $this->assertFileDoesNotExist($cacheFile2);
    }


        private function removeDirectoryRecursively($dir)
        {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
            continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
            $this->removeDirectoryRecursively($path);
            } else {
            unlink($path);
            }
        }
        rmdir($dir);
        }

        public function testUseDefaultDriverCreatesCacheDirInProjectRoot()
        {
        $cacheer = new Cacheer();
        $driver = new CacheDriver($cacheer);

        $projectRoot = EnvHelper::getRootPath();
        $expectedCacheDir = $projectRoot . DIRECTORY_SEPARATOR . "CacheerPHP" . DIRECTORY_SEPARATOR . "Cache";

        if (is_dir($expectedCacheDir)) {
            $this->removeDirectoryRecursively($expectedCacheDir);
        }

        $driver->useDefaultDriver();

        $this->assertDirectoryExists($expectedCacheDir);

        if (is_dir($expectedCacheDir)) {
            $this->removeDirectoryRecursively($expectedCacheDir);
        }
        }

        public function testPutCacheWithNamespace()
        {
        $cacheKey = 'namespace_key';
        $data = 'namespace_data';
        $namespace = 'my_namespace';

        $this->cache->putCache($cacheKey, $data, $namespace);
        $this->assertTrue($this->cache->isSuccess());

        $cachedData = $this->cache->getCache($cacheKey, $namespace);
        $this->assertEquals($data, $cachedData);
        }

        public function testClearCacheWithNamespace()
        {
        $cacheKey = 'namespace_key_clear';
        $data = 'namespace_data_clear';
        $namespace = 'clear_namespace';

        $this->cache->putCache($cacheKey, $data, $namespace);
        $this->assertTrue($this->cache->isSuccess());

        $this->cache->clearCache($cacheKey, $namespace);
        $this->assertTrue($this->cache->isSuccess());

        $cachedData = $this->cache->getCache($cacheKey, $namespace);
        $this->assertFalse($this->cache->isSuccess());
        $this->assertNull($cachedData);
        }

        public function testFlushCacheRemovesNamespacedFiles()
        {
        $cacheKey = 'ns_flush_key';
        $data = 'ns_flush_data';
        $namespace = 'flush_namespace';

        $this->cache->putCache($cacheKey, $data, $namespace);
        $this->assertTrue($this->cache->isSuccess());

        $this->cache->flushCache();

        $cachedData = $this->cache->getCache($cacheKey, $namespace);
        $this->assertFalse($this->cache->isSuccess());
        $this->assertNull($cachedData);
        }

        public function testAppendCacheWithDifferentTypes()
        {
        $cacheKey = 'append_type_key';
        $initialData = ['a' => 1];
        $additionalData = ['b' => 2];
        $expectedData = ['a' => 1, 'b' => 2];

        $this->cache->putCache($cacheKey, $initialData);
        $this->cache->appendCache($cacheKey, $additionalData);
        $this->assertEquals($expectedData, $this->cache->getCache($cacheKey));

        $this->cache->appendCache($cacheKey, ['c' => 'string']);
        $expectedData['c'] = 'string';
        $this->assertEquals($expectedData, $this->cache->getCache($cacheKey));
        }

        public function test_tag_and_flush_tag_in_file_driver()
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

        $this->assertNull($this->cache->getCache($k1));
        $this->assertNull($this->cache->getCache($k2));
        }

        public function test_tag_with_namespace_and_flush_tag_in_file_driver()
        {
        $ns = 'nsA';
        $k1 = 'k1';
        $k2 = 'k2';
        $this->cache->putCache($k1, 'v1', $ns);
        $this->cache->putCache($k2, 'v2', $ns);

        $ok = $this->cache->tag('groupNS', $ns . ':' . $k1, $ns . ':' . $k2);
        $this->assertTrue($ok);

        $this->cache->flushTag('groupNS');
        $this->assertNull($this->cache->getCache($k1, $ns));
        $this->assertNull($this->cache->getCache($k2, $ns));
        }

}
