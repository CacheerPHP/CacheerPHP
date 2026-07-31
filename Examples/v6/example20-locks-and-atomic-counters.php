<?php

declare(strict_types=1);

/**
 * Example 20 — Distributed locks & atomic counters (v6)
 *
 * Two concurrency-safe primitives from the store contract:
 *
 *   LockingStore::lock($name, $ttl)  → a named cross-process mutex.
 *       $lock->acquire(): bool          try once, non-blocking
 *       $lock->block($seconds): bool    wait up to N seconds
 *       $lock->release(): bool
 *   AtomicStore::increment(...)      → lost-update-free counters.
 *
 * Both work across processes on the File, Database, and Redis stores. Use a lock
 * for "must happen once" side effects; use increment() for plain counters and
 * remember()/flexible() for stampede-safe recomputation (example 21).
 *
 * Run: php Examples/v6/example20-locks-and-atomic-counters.php
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

// Reset the counter so repeated runs are deterministic.
$cache->delete('views:post:1');

// ── Run a critical section under a lock ──────────────────────────────────────
$lock = $store->lock('rebuild-report', Ttl::seconds(30));
if ($lock->acquire()) {
    try {
        $result = 'report rebuilt at ' . date('H:i:s');
        echo $result . PHP_EOL;
    } finally {
        $lock->release();
    }
}

// ── Wait up to 5s for a contended lock, then run ─────────────────────────────
$invoice = $store->lock('invoice:42', Ttl::seconds(30));
if ($invoice->block(5.0)) {
    try {
        echo "invoice 42 charged\n";
    } finally {
        $invoice->release();
    }
} else {
    echo "could not acquire the lock in time\n";
}

// ── Atomic counters ──────────────────────────────────────────────────────────
$key = Key::named('views:post:1');
$store->increment($key, 1, initial: 0);   // → 1
$store->increment($key, 10);              // → 11
$store->increment($key, -3);              // → 8  (decrement)

echo 'views:post:1 = ' . $cache->get('views:post:1') . PHP_EOL;
assert($cache->get('views:post:1') === 8);

// Under concurrency every increment is applied exactly once — no lost updates.
echo "OK\n";
