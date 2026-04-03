<?php

use PHPUnit\Framework\TestCase;
use Silviooosilva\CacheerPhp\Config\Option\Builder\OptionBuilder;

class DatabaseOptionBuilderFeatureTest extends TestCase
{
    public function test_it_builds_database_options()
    {
        $options = OptionBuilder::forDatabase()
          ->table('cache_items')
          ->loggerPath(__DIR__ . '/cache/logger.log')
          ->expirationTime('12 hours')
          ->flushAfter('7 days')
          ->build();

        $this->assertArrayHasKey('table', $options);
        $this->assertArrayHasKey('loggerPath', $options);
        $this->assertArrayHasKey('expirationTime', $options);
        $this->assertArrayHasKey('flushAfter', $options);

        $this->assertSame('cache_items', $options['table']);
        $this->assertSame(__DIR__ . '/cache/logger.log', $options['loggerPath']);
        $this->assertSame('12 hours', $options['expirationTime']);
        $this->assertSame('7 days', $options['flushAfter']);
    }

    public function test_it_allows_timebuilder_for_database()
    {
        $options = OptionBuilder::forDatabase()
          ->expirationTime()->hour(6)
          ->flushAfter()->week(1)
          ->build();

        $this->assertSame('6 hours', $options['expirationTime']);
        $this->assertSame('1 weeks', $options['flushAfter']);
    }
}
