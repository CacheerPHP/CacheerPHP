<?php

declare(strict_types=1);

/**
 * Example 21 — Stampede protection & stale-while-revalidate (v6)
 *
 *   remember($key, $ttl, $cb)                 → single-flight: a cold key hit by
 *                                               a burst runs the callback once.
 *   flexible($key, $fresh, $stale, $cb)       → serve fresh directly; serve stale
 *                                               while one worker refreshes; only
 *                                               recompute once older than $stale.
 *
 * Run: php Examples/v6/example21-stampede-and-swr.php
 */

require __DIR__ . '/../../vendor/autoload.php';

use Silviooosilva\CacheerPhp\Cacheer;

$cache = Cacheer::file(__DIR__ . '/cache');

// Clean slate so the demo is deterministic.
$cache->delete('dashboard:stats');
$cache->delete('home');

// ── Stampede-safe remember() ─────────────────────────────────────────────────
$calls = 0;
$build = function () use (&$calls): string {
    $calls++;

    return "stats #{$calls}";
};

echo $cache->remember('dashboard:stats', 300, $build) . PHP_EOL; // computes → stats #1
echo $cache->remember('dashboard:stats', 300, $build) . PHP_EOL; // cached   → stats #1
echo "build callback ran {$calls} time(s)\n";                     // 1
assert($calls === 1);

echo PHP_EOL;

// ── Stale-while-revalidate with flexible() ───────────────────────────────────
// Fresh for 2s; may be served stale for up to 5s while it refreshes.
$renders = 0;
$render = function () use (&$renders): string {
    $renders++;

    return "home v{$renders}";
};

echo $cache->flexible('home', 2, 5, $render) . PHP_EOL; // cold  → home v1
echo $cache->flexible('home', 2, 5, $render) . PHP_EOL; // fresh → home v1
echo "renders so far: {$renders}\n";                     // 1
assert($renders === 1);

sleep(3); // now past the 2s fresh window, still within the 5s stale window

// The caller in the stale window triggers a refresh and ends up with fresh data.
echo $cache->flexible('home', 2, 5, $render) . PHP_EOL; // stale → refresh → home v2
echo "renders after refresh: {$renders}\n";              // 2
assert($renders === 2);

echo "OK\n";
