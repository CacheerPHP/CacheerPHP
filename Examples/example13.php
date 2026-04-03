<?php

/**
 * Example 13 — Caching falsy values correctly (v5.0.0)
 *
 * In v4.x the library used !empty() to detect cache hits, which treated 0,
 * 0.0, '', '0', false, and [] as misses — silently re-executing callbacks
 * and ignoring stored counters.
 *
 * v5.0.0 replaces every !empty() check with isSuccess(), so all PHP values
 * (including falsy ones) round-trip correctly through the cache.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Silviooosilva\CacheerPhp\Cacheer;

$Cacheer = new Cacheer();
$Cacheer->setDriver()->useArrayDriver();

// ── 1. Caching integer 0 ──────────────────────────────────────────────────────

$Cacheer->putCache('page_views', 0);
$views = $Cacheer->getCache('page_views');

echo '--- Integer 0 ---' . PHP_EOL;
echo 'Hit     : ' . var_export($Cacheer->isSuccess(), true) . PHP_EOL;  // true
echo 'Value   : ' . var_export($views, true) . PHP_EOL;                  // 0

// ── 2. Caching boolean false ───────────────────────────────────────────────────

$Cacheer->putCache('feature_enabled', false);
$flag = $Cacheer->getCache('feature_enabled');

echo PHP_EOL . '--- Boolean false ---' . PHP_EOL;
echo 'Hit     : ' . var_export($Cacheer->isSuccess(), true) . PHP_EOL;  // true
echo 'Value   : ' . var_export($flag, true) . PHP_EOL;                   // false

// ── 3. Caching empty string ────────────────────────────────────────────────────

$Cacheer->putCache('optional_suffix', '');
$suffix = $Cacheer->getCache('optional_suffix');

echo PHP_EOL . '--- Empty string ---' . PHP_EOL;
echo 'Hit     : ' . var_export($Cacheer->isSuccess(), true) . PHP_EOL;  // true
echo 'Value   : ' . var_export($suffix, true) . PHP_EOL;                 // ''

// ── 4. Caching empty array ─────────────────────────────────────────────────────

$Cacheer->putCache('search_results', []);
$results = $Cacheer->getCache('search_results');

echo PHP_EOL . '--- Empty array ---' . PHP_EOL;
echo 'Hit     : ' . var_export($Cacheer->isSuccess(), true) . PHP_EOL;  // true
echo 'Value   : ' . var_export($results, true) . PHP_EOL;                // array ()

// ── 5. increment() starting from 0 ────────────────────────────────────────────

$Cacheer->putCache('counter', 0);
$Cacheer->increment('counter');
$Cacheer->increment('counter');
$Cacheer->increment('counter', 5);

echo PHP_EOL . '--- increment() from 0 ---' . PHP_EOL;
echo 'Counter : ' . $Cacheer->getCache('counter') . PHP_EOL;  // 7

// ── 6. remember() does NOT re-call the closure for a cached falsy value ────────

$callCount = 0;

$Cacheer->remember('zero_result', 300, function () use (&$callCount) {
    $callCount++;
    return 0; // a valid "no results" result
});

$Cacheer->remember('zero_result', 300, function () use (&$callCount) {
    $callCount++;      // must NOT be reached
    return 999;
});

echo PHP_EOL . '--- remember() with falsy cached value ---' . PHP_EOL;
echo 'Callback called : ' . $callCount . ' time(s)' . PHP_EOL;     // 1
echo 'Cached value    : ' . $Cacheer->getCache('zero_result') . PHP_EOL;  // 0
