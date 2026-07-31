<?php

declare(strict_types=1);

/**
 * Example 02 — Custom expiration (v6)
 *
 * v5 → v6 mapping:
 *   OptionBuilder::forFile()->expirationTime()->hour(2)  →  a per-write TTL
 *   $c->putCache($key, $value)  (global TTL)             →  $c->set($key, $value, ttl: '2 hours')
 *
 * TTLs accept int seconds, human strings ("2 hours", "30 minutes"),
 * a \DateInterval, or null (store forever). To set a default TTL for every
 * write, use Cacheer::build()->defaultTtl('2 hours')->create() (see example 11).
 *
 * Run: php Examples/v6/example02-custom-expiration.php
 */

require __DIR__ . '/../../vendor/autoload.php';

use Silviooosilva\CacheerPhp\Cacheer;

$cache = Cacheer::file(__DIR__ . '/cache');

$cacheKey = 'daily_stats';
$dailyStats = [
    'visits' => 1500,
    'signups' => 35,
    'revenue' => 500.75,
];

// TTL travels with the write — this entry lives for two hours.
$cache->set($cacheKey, $dailyStats, ttl: '2 hours');

$cachedStats = $cache->get($cacheKey);

echo "Cache Found (TTL 2h): ";
print_r($cachedStats);

assert($cachedStats === $dailyStats);
assert($cache->has($cacheKey) === true);

echo "OK\n";
