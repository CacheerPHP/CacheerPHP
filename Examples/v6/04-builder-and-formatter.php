<?php

declare(strict_types=1);

/**
 * v6 ergonomics: the fluent Cacheer::build() and the CacheDataFormatter.
 *
 * Run: php examples/v6/04-builder-and-formatter.php
 */

require __DIR__ . '/../../vendor/autoload.php';

use Silviooosilva\CacheerPhp\Cacheer;
use Silviooosilva\CacheerPhp\Kernel\PolicyCacheer;
use Silviooosilva\CacheerPhp\Support\CacheDataFormatter;

// Assemble a store + pipeline + default policy in one chain.
$cache = Cacheer::build()
    ->inMemory()
    ->defaultTtl('10 minutes')
    ->jitter(0.10)
    ->create();

assert($cache instanceof PolicyCacheer); // a policy was set

$cache->set('user:1', ['id' => 1, 'name' => 'Ada']);

// 1) Format any value standalone.
$json = (new CacheDataFormatter($cache->get('user:1')))->toJson();
assert(json_decode($json, true) === ['id' => 1, 'name' => 'Ada']);

// 2) The fluent formatted() view — get() returns a formatter.
$plain = Cacheer::inMemory();
$plain->set('user:1', ['id' => 1, 'name' => 'Ada']);

$formatted = $plain->formatted();
assert($formatted->get('user:1')->toArray() === ['id' => 1, 'name' => 'Ada']);
assert(json_decode($formatted->get('user:1')->toJson(), true) === ['id' => 1, 'name' => 'Ada']);

// The base get() stays raw.
$plain->set('flag', false);
assert($plain->get('flag') === false);
assert($formatted->get('flag')->value() === false);

echo "OK\n";
