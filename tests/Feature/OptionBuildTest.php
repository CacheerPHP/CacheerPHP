<?php

use PHPUnit\Framework\TestCase;
use Silviooosilva\CacheerPhp\Cacheer;
use Silviooosilva\CacheerPhp\Config\Option\Builder\OptionBuilder;

class OptionBuildTest extends TestCase
{
    private $cache;
    private $cacheDir;

    protected function setUp(): void
    {
        $this->cacheDir = __DIR__ . '/cache';
        if (!file_exists($this->cacheDir) || !is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0755, true);
        }

        $this->cache = new Cacheer();
    }

    protected function tearDown(): void
    {
        $this->cache->flushCache();
    }

    public function test_it_can_set_cache_directory()
    {
        $cacheDir = __DIR__ . '/cache';

        $options = OptionBuilder::forFile()
        ->dir($cacheDir)
        ->build();

        $this->assertArrayHasKey('cacheDir', $options);
        $this->assertEquals($cacheDir, $options['cacheDir']);
    }

    public function test_it_can_set_logger_path()
    {
        $loggerPath = __DIR__ . '/cache/logger.log';

        $options = OptionBuilder::forFile()
        ->loggerPath($loggerPath)
        ->build();

        $this->assertArrayHasKey('loggerPath', $options);
        $this->assertEquals($loggerPath, $options['loggerPath']);
    }

    public function test_it_can_set_expiration_time()
    {

        $options = OptionBuilder::forFile()
        ->expirationTime('2 hours')
        ->build();

        $this->assertArrayHasKey('expirationTime', $options);
        $this->assertEquals('2 hours', $options['expirationTime']);
    }

    public function test_it_can_set_flush_after()
    {
        $options = OptionBuilder::forFile()
          ->flushAfter('11 seconds')
          ->build();

        $this->assertArrayHasKey('flushAfter', $options);
        $this->assertEquals('11 seconds', $options['flushAfter']);
    }

    public function test_it_can_set_multiple_options_together()
    {
        $cacheDir = __DIR__ . '/cache';

        $options = OptionBuilder::forFile()
              ->dir($cacheDir)
              ->loggerPath($cacheDir . '/logger.log')
              ->expirationTime('1 day')
              ->flushAfter('30 minutes')
              ->build();

        $this->assertEquals([
            'cacheDir'       => $cacheDir,
            'loggerPath'     => $cacheDir . '/logger.log',
            'expirationTime' => '1 day',
            'flushAfter'     => '30 minutes',
        ], $options);
    }

    public function test_it_allows_setting_expiration_time_with_timebuilder()
    {
        $options = OptionBuilder::forFile()->expirationTime()->week(1)->build();
        $this->assertArrayHasKey('expirationTime', $options);
        $this->assertEquals('1 weeks', $options['expirationTime']);
    }

    public function test_it_allows_setting_flush_after_with_timebuilder()
    {
        $options = OptionBuilder::forFile()->flushAfter()->second(10)->build();
        $this->assertArrayHasKey('flushAfter', $options);
        $this->assertEquals('10 seconds', $options['flushAfter']);
    }

    public function test_it_can_set_multiple_options_together_with_timebuilder()
    {
        $cacheDir = __DIR__ . '/cache';
        $options = OptionBuilder::forFile()
              ->dir($cacheDir)
              ->loggerPath($cacheDir . '/logger.log')
              ->expirationTime()->week(1)
              ->flushAfter()->minute(10)
              ->build();

        $this->assertEquals([
                'cacheDir'       => $cacheDir,
                'loggerPath'     => $cacheDir . '/logger.log',
                'expirationTime' => '1 weeks',
                'flushAfter'     => '10 minutes',
            ], $options);
    }

    public function test_it_returns_empty_array_when_no_options_are_set()
    {
        $options = OptionBuilder::forFile()->build();
        $this->assertIsArray($options);
        $this->assertEmpty($options);
    }

}
