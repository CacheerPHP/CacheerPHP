<?php

declare(strict_types=1);

/**
 * Example 07 — Merging into a cached value (v6)
 *
 * v5 had a dedicated appendCache() that merged data into an existing array
 * entry. v6 deliberately keeps the store contract tiny (get/set/delete/clear),
 * so there is no appendCache(). The same result is an explicit read-modify-write
 * you can see and reason about:
 *
 *   $current = $cache->get($key, []);
 *   $cache->set($key, array_merge($current, $extra));
 *
 * When several processes may append concurrently, wrap the read-modify-write in
 * a lock (see example 20) so updates are not lost.
 *
 * Run: php Examples/v6/example07-append-cache.php
 */

require __DIR__ . '/../../vendor/autoload.php';

use Silviooosilva\CacheerPhp\Cacheer;

$cache = Cacheer::file(__DIR__ . '/cache');

$cacheKey = 'user_profile_1';

$cache->set($cacheKey, [
    'id' => 1,
    'name' => 'Sílvio Silva',
    'email' => 'gasparsilvio7@gmail.com',
]);

echo "Before merge:\n";
print_r($cache->get($cacheKey));

// Merge additional fields into the stored array.
$extra = [
    'house_number' => 2130,
    'phone' => '(999)999-9999',
];

$current = $cache->get($cacheKey, []);
$cache->set($cacheKey, array_merge($current, $extra));

echo "After merge:\n";
$merged = $cache->get($cacheKey);
print_r($merged);

assert($merged['phone'] === '(999)999-9999');
assert($merged['name'] === 'Sílvio Silva');

echo "OK\n";
