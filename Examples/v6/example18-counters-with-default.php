<?php

declare(strict_types=1);

/**
 * Example 18 — Counters with create-on-miss and a TTL (v6)
 *
 * AtomicStore::increment() seeds and bumps a counter atomically:
 *
 *   increment(Key $key, int $amount = 1, ?int $initial = null, ?Ttl $ttl = null): int
 *
 *   - $initial = null → the key must already exist, otherwise nothing happens.
 *   - $initial given  → on a miss, the entry is created as ($initial + $amount),
 *                       with the optional $ttl applied.
 *
 * There is no separate decrement() — decrementing is increment() with a negative
 * amount. increment() returns the new value.
 *
 * Run: php Examples/v6/example18-counters-with-default.php
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
$cache->clear();

// ── Create-on-miss with an explicit initial value ────────────────────────────
$store->increment(Key::named('page-views'), 1, initial: 0);   // → 1
$views = $store->increment(Key::named('page-views'), 1, initial: 0); // → 2
echo 'page-views: ' . $views . PHP_EOL;
assert($views === 2);

// ── Seed from a non-zero base (initial + amount) ─────────────────────────────
$budget = $store->increment(Key::named('budget'), 10, initial: 100); // 100 + 10
echo 'budget: ' . $budget . PHP_EOL;
assert($budget === 110);

// ── Decrement = increment() with a negative amount ───────────────────────────
$stock = $store->increment(Key::named('stock'), -5, initial: 100);   // 100 - 5
echo 'stock: ' . $stock . PHP_EOL;
assert($stock === 95);

// ── Time-bounded counter (rate-limit window) ─────────────────────────────────
$store->increment(Key::named('rate-window'), 1, initial: 0, ttl: Ttl::minutes(1));
echo 'rate-window: ' . $cache->get('rate-window') . PHP_EOL;
assert($cache->get('rate-window') === 1);

echo "OK\n";
