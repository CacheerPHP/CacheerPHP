<?php

declare(strict_types=1);

/**
 * Example 08 — Renew a TTL without rewriting the value (v6)
 *
 * v5 → v6 mapping:
 *   $c->renewCache($key, 3600)  →  $store->touch(Key::named($key), Ttl::seconds(3600))
 *
 * Extending an entry's lifetime is the TouchStore capability. It lives on the
 * store contract (every built-in store implements it), so you build the store,
 * keep a reference to it for capability calls, and drive normal reads/writes
 * through the Cacheer kernel wrapping it.
 *
 * Run: php Examples/v6/example08-renew-ttl.php
 */

require __DIR__ . '/../../vendor/autoload.php';

use Silviooosilva\CacheerPhp\Cacheer;
use Silviooosilva\CacheerPhp\Kernel\Key;
use Silviooosilva\CacheerPhp\Kernel\Ttl;
use Silviooosilva\CacheerPhp\Stores\FileStore;
use Silviooosilva\CacheerPhp\Support\SystemClock;

$clock = new SystemClock();
$store = new FileStore(__DIR__ . '/cache', clock: $clock);
$cache = new Cacheer($store, $clock);

$cacheKey = 'user_profile_01';

// Store with a 5-minute TTL.
$cache->set($cacheKey, ['id' => 1, 'name' => 'Sílvio Silva'], ttl: 300);
echo "Cache Found: ";
print_r($cache->get($cacheKey));

// Extend the same entry to one hour — the value is untouched.
$renewed = $store->touch(Key::named($cacheKey), Ttl::hours(1));

echo 'Cache renewed: ' . var_export($renewed, true) . PHP_EOL;
assert($renewed === true);
assert($cache->get($cacheKey) === ['id' => 1, 'name' => 'Sílvio Silva']);

echo "OK\n";
