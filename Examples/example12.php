<?php

/**
 * Example 12 — stats(), resetInstance(), and setInstance() (v5.0.0)
 *
 * Three new methods help you inspect and control the shared static singleton
 * that backs the Cacheer static facade.
 *
 *   stats()         — returns the active driver class name and feature flags.
 *   resetInstance() — clears the singleton so the next static call gets a
 *                     fresh default instance (useful in tests / long-running
 *                     processes that switch configurations).
 *   setInstance()   — injects a pre-configured Cacheer instance as the
 *                     singleton, so all subsequent Cacheer::*() static calls
 *                     go through it.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Silviooosilva\CacheerPhp\Cacheer;
use Silviooosilva\CacheerPhp\CacheStore\ArrayCacheStore;

// ── 1. stats() ─────────────────────────────────────────────────────────────────

$Cacheer = new Cacheer();
$Cacheer->setDriver()->useArrayDriver();
$Cacheer->useCompression(true);
$Cacheer->useEncryption('demo-key-000000000000000000000000');

$stats = $Cacheer->stats();

echo '--- stats() ---' . PHP_EOL;
echo 'Driver      : ' . $stats['driver']      . PHP_EOL;
echo 'Compression : ' . var_export($stats['compression'], true) . PHP_EOL;
echo 'Encryption  : ' . var_export($stats['encryption'],  true) . PHP_EOL;
echo PHP_EOL;

// ── 2. setInstance() — use a custom instance as the static facade target ───────

$custom = new Cacheer();
$custom->setDriver()->useArrayDriver();

Cacheer::setInstance($custom);

// All static calls now go through $custom.
Cacheer::putCache('static_key', 'hello from static facade');

echo '--- setInstance() ---' . PHP_EOL;
echo Cacheer::getCache('static_key') . PHP_EOL;    // hello from static facade
echo $custom->getCache('static_key') . PHP_EOL;    // same value via instance
echo PHP_EOL;

// ── 3. resetInstance() — tear down the singleton ──────────────────────────────

Cacheer::resetInstance();

// The next static call creates a brand-new default singleton.
// The key stored on $custom is gone from the static context.
$fresh = Cacheer::getCache('static_key');

echo '--- resetInstance() ---' . PHP_EOL;
echo 'After reset, static key found: ' . var_export($fresh !== null, true) . PHP_EOL; // false

// ── 4. getCacheStore() — inspect the active driver via instance ────────────────

$instance = new Cacheer();
$instance->setDriver()->useArrayDriver();

echo '--- getCacheStore() ---' . PHP_EOL;
echo get_class($instance->getCacheStore()) . PHP_EOL;  // ...ArrayCacheStore

// Clean up singleton
Cacheer::resetInstance();
