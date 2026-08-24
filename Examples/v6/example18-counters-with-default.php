<?php

declare(strict_types=1);

/**
 * Example 18 — Counters with create-on-miss and a TTL (v6)
 *
 * increment() and decrement() sit on the cache and bump a counter atomically:
 *
 *   increment(string|Key $key, int $amount = 1, ?int $initial = null, $ttl = null): int
 *
 *   - $initial = null → the key must already exist, otherwise nothing happens.
 *   - $initial given  → on a miss, the entry is created as ($initial + $amount),
 *                       with the optional $ttl applied.
 *
 * Both return the new value. decrement() is increment() with the sign flipped —
 * v5 had both names, and so does v6.
 *
 * Run: php Examples/v6/example18-counters-with-default.php
 */

require __DIR__ . '/../../vendor/autoload.php';

use Silviooosilva\CacheerPhp\Cacheer;

$cache = Cacheer::file(__DIR__ . '/cache');
$cache->clear();

// ── Create-on-miss with an explicit initial value ────────────────────────────
$cache->increment('page-views', 1, initial: 0);            // → 1
$views = $cache->increment('page-views', 1, initial: 0);   // → 2
echo 'page-views: ' . $views . PHP_EOL;
assert($views === 2);

// ── Seed from a non-zero base (initial + amount) ─────────────────────────────
$budget = $cache->increment('budget', 10, initial: 100);   // 100 + 10
echo 'budget: ' . $budget . PHP_EOL;
assert($budget === 110);

// ── decrement() reads better than a negative increment ───────────────────────
$stock = $cache->decrement('stock', 5, initial: 100);      // 100 - 5
echo 'stock: ' . $stock . PHP_EOL;
assert($stock === 95);

// ── Time-bounded counter (rate-limit window) ─────────────────────────────────
$cache->increment('rate-window', 1, initial: 0, ttl: '1 minute');
echo 'rate-window: ' . $cache->get('rate-window') . PHP_EOL;
assert($cache->get('rate-window') === 1);

// ── Counters respect scope, like every other operation ───────────────────────
$cache->in('tenant-a')->increment('signups', 1, initial: 0);
$cache->in('tenant-b')->increment('signups', 5, initial: 0);
echo 'tenant-a signups: ' . $cache->in('tenant-a')->get('signups') . PHP_EOL;
echo 'tenant-b signups: ' . $cache->in('tenant-b')->get('signups') . PHP_EOL;
assert($cache->in('tenant-a')->get('signups') === 1);
assert($cache->in('tenant-b')->get('signups') === 5);

echo "OK\n";
