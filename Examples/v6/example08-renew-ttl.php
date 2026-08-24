<?php

declare(strict_types=1);

/**
 * Example 08 — Renew a TTL without rewriting the value (v6)
 *
 * v5 → v6 mapping:
 *   $c->renewCache($key, 3600)  →  $cache->touch($key, 3600)
 *
 * Extending an entry's lifetime is the TouchStore capability, and like every
 * capability it is reachable straight off the cache — with this cache's scope
 * already applied, so it always targets the same entry your reads do.
 *
 * Run: php Examples/v6/example08-renew-ttl.php
 */

require __DIR__ . '/../../vendor/autoload.php';

use Silviooosilva\CacheerPhp\Cacheer;

$cache = Cacheer::file(__DIR__ . '/cache');

$cacheKey = 'user_profile_01';

// Store with a 5-minute TTL.
$cache->set($cacheKey, ['id' => 1, 'name' => 'Sílvio Silva'], ttl: 300);
echo "Cache Found: ";
print_r($cache->get($cacheKey));

// Extend the same entry to one hour — the value is untouched.
$renewed = $cache->touch($cacheKey, '1 hour');

echo 'Cache renewed: ' . var_export($renewed, true) . PHP_EOL;
assert($renewed === true);
assert($cache->get($cacheKey) === ['id' => 1, 'name' => 'Sílvio Silva']);

// A miss cannot be renewed.
assert($cache->touch('never_written', 60) === false);

// Scoping applies here as it does everywhere else.
$reports = $cache->in('reports');
$reports->set($cacheKey, 'a different entry', ttl: 60);
$reports->touch($cacheKey, 3600);
assert($cache->get($cacheKey) === ['id' => 1, 'name' => 'Sílvio Silva']); // untouched

echo "OK\n";
