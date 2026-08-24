<?php

declare(strict_types=1);

/**
 * Example 10 — Flexible TTLs: int, string, DateInterval, null (v6)
 *
 * Every TTL argument (set(), remember(), and TouchStore::touch()) accepts:
 *   - int           → seconds
 *   - string        → "1 hour", "30 minutes", etc.
 *   - \DateInterval → converted to seconds automatically
 *   - null          → store forever
 *
 * Run: php Examples/v6/example10-dateinterval-ttl.php
 */

require __DIR__ . '/../../vendor/autoload.php';

use Silviooosilva\CacheerPhp\Cacheer;
use Silviooosilva\CacheerPhp\Stores\ArrayStore;
use Silviooosilva\CacheerPhp\Support\SystemClock;

$clock = new SystemClock();
$store = new ArrayStore($clock);
$cache = new Cacheer($store, $clock);

// ── 1. int TTL ───────────────────────────────────────────────────────────────
$cache->set('key_int', 'integer TTL', ttl: 3600);
echo $cache->get('key_int') . PHP_EOL;

// ── 2. string TTL ────────────────────────────────────────────────────────────
$cache->set('key_string', 'string TTL', ttl: '2 hours');
echo $cache->get('key_string') . PHP_EOL;

// ── 3. DateInterval TTL ──────────────────────────────────────────────────────
$cache->set('key_interval', 'DateInterval TTL', ttl: new DateInterval('PT45M'));
echo $cache->get('key_interval') . PHP_EOL;

// ── 4. null TTL — store forever ──────────────────────────────────────────────
$cache->set('key_forever', 'lives forever', ttl: null);
echo $cache->get('key_forever') . PHP_EOL;

// ── 5. remember() with a DateInterval ────────────────────────────────────────
$calls = 0;
$build = function () use (&$calls): string {
    $calls++;

    return 'computed value — ' . date('Y-m-d');
};

$first = $cache->remember('computed', new DateInterval('P1D'), $build);
$second = $cache->remember('computed', new DateInterval('P1D'), $build);
echo $first . PHP_EOL;
assert($first === $second);
assert($calls === 1); // the closure ran once

// ── 6. Renew a TTL with a DateInterval (TouchStore) ──────────────────────────
$cache->set('renewable', 'data', ttl: 60);
$touched = $cache->touch('renewable', new DateInterval('PT2H'));
echo 'Renewed: ' . var_export($touched, true) . PHP_EOL;
assert($touched === true);

echo "OK\n";
