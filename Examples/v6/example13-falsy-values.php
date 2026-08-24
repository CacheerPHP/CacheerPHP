<?php

declare(strict_types=1);

/**
 * Example 13 — Caching falsy values correctly (v6)
 *
 * All PHP values — including 0, 0.0, '', '0', false, and [] — round-trip through
 * the cache and count as genuine hits. Because get() returns your $default on a
 * miss, use entry()->isHit() when you must tell a cached false/null apart from a
 * missing key.
 *
 * Run: php Examples/v6/example13-falsy-values.php
 */

require __DIR__ . '/../../vendor/autoload.php';

use Silviooosilva\CacheerPhp\Cacheer;
use Silviooosilva\CacheerPhp\Stores\ArrayStore;
use Silviooosilva\CacheerPhp\Support\SystemClock;

$clock = new SystemClock();
$store = new ArrayStore($clock);
$cache = new Cacheer($store, $clock);

// ── Falsy values are stored and hit exactly ──────────────────────────────────
$cache->set('page_views', 0);
$cache->set('feature_enabled', false);
$cache->set('optional_suffix', '');
$cache->set('search_results', []);

echo 'Integer 0  : ' . var_export($cache->get('page_views'), true) . PHP_EOL;      // 0
echo 'False      : ' . var_export($cache->get('feature_enabled'), true) . PHP_EOL; // false
echo 'Empty str  : ' . var_export($cache->get('optional_suffix'), true) . PHP_EOL; // ''
echo 'Empty array: ' . var_export($cache->get('search_results'), true) . PHP_EOL;  // array()

assert($cache->get('page_views') === 0);
assert($cache->get('feature_enabled') === false);

// ── entry() distinguishes a cached false from a miss ─────────────────────────
$hit = $cache->entry('feature_enabled');
$miss = $cache->entry('never_stored');
echo 'feature_enabled is a hit : ' . var_export($hit->isHit(), true) . PHP_EOL;  // true
echo 'never_stored is a hit    : ' . var_export($miss->isHit(), true) . PHP_EOL; // false
assert($hit->isHit() === true && $hit->value() === false);
assert($miss->isMiss() === true);

// ── increment() from a stored 0 (AtomicStore) ────────────────────────────────
$cache->increment('counter', 1, initial: 0);
$cache->increment('counter', 1);
$cache->increment('counter', 5);
echo 'Counter : ' . $cache->get('counter') . PHP_EOL; // 7
assert($cache->get('counter') === 7);

// ── remember() does NOT re-run for a cached falsy value ──────────────────────
$calls = 0;
$cache->remember('zero_result', 300, function () use (&$calls): int {
    $calls++;

    return 0;
});
$cache->remember('zero_result', 300, function () use (&$calls): int {
    $calls++; // must not run

    return 999;
});
echo "Callback called {$calls} time(s)\n"; // 1
assert($calls === 1);
assert($cache->get('zero_result') === 0);

echo "OK\n";
