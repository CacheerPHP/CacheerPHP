<?php

/**
 * Example 21 — Stampede Protection & Stale-While-Revalidate
 *
 * (new in v5.2.0)
 *
 *   - remember()  is now stampede-safe: a concurrent miss runs the callback
 *                 once (single-flight), not once per request.
 *   - flexible()  adds stale-while-revalidate: serve fresh values directly,
 *                 serve stale ones while a single worker refreshes, and
 *                 recompute only once the value is older than the stale window.
 */

require_once __DIR__ . "/../vendor/autoload.php";

use Silviooosilva\CacheerPhp\Cacheer;
use Silviooosilva\CacheerPhp\Config\Option\Builder\OptionBuilder;

$options = OptionBuilder::forFile()
    ->dir(__DIR__ . "/cache")
    ->build();

$Cacheer = new Cacheer($options);

// Start from a clean slate so the demo is deterministic between runs.
$Cacheer->clearCache("dashboard:stats");
$Cacheer->clearCache("home");

// --- Stampede-safe remember() ----------------------------------------------
// On a cold key, even a burst of concurrent requests runs the callback once.
$calls = 0;
$build = function () use (&$calls) {
    $calls++;
    return "stats #{$calls}";
};

echo $Cacheer->remember("dashboard:stats", 300, $build) . PHP_EOL;   // computes → "stats #1"
echo $Cacheer->remember("dashboard:stats", 300, $build) . PHP_EOL;   // cached   → "stats #1"
echo "build callback ran {$calls} time(s)" . PHP_EOL;                 // 1

echo PHP_EOL;

// --- Stale-while-revalidate with flexible() --------------------------------
// fresh for 2s; may be served stale for up to 5s while it refreshes.
$renders = 0;
$render = function () use (&$renders) {
    $renders++;
    return "home v{$renders}";
};

echo $Cacheer->flexible("home", 2, 5, $render) . PHP_EOL;    // cold  → "home v1"
echo $Cacheer->flexible("home", 2, 5, $render) . PHP_EOL;    // fresh → "home v1"
echo "renders so far: {$renders}" . PHP_EOL;                  // 1

sleep(3); // now older than the 2s fresh window, still within the 5s stale window

// The first caller in the stale window refreshes inline and returns fresh data;
// concurrent callers would get the cached value instantly.
echo $Cacheer->flexible("home", 2, 5, $render) . PHP_EOL;    // stale → refresh → "home v2"
echo "renders after refresh: {$renders}" . PHP_EOL;           // 2
