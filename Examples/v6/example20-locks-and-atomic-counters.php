<?php

declare(strict_types=1);

/**
 * Example 20 — Distributed locks & atomic counters (v6)
 *
 * Two concurrency-safe primitives, both on the cache:
 *
 *   lock($name, $ttl)   → a named cross-process mutex, namespaced by scope.
 *       $lock->acquire(): bool          try once, non-blocking
 *       $lock->block($seconds): bool    wait up to N seconds
 *       $lock->release(): bool
 *   increment(...)      → lost-update-free counters.
 *
 * Both work across processes on the File, Database, and Redis stores. Use a lock
 * for "must happen once" side effects; use increment() for plain counters and
 * remember()/flexible() for stampede-safe recomputation (example 21).
 *
 * Run: php Examples/v6/example20-locks-and-atomic-counters.php
 */

require __DIR__ . '/../../vendor/autoload.php';

use Silviooosilva\CacheerPhp\Cacheer;
use Silviooosilva\CacheerPhp\Contracts\AtomicStore;
use Silviooosilva\CacheerPhp\Contracts\LockingStore;

$cache = Cacheer::file(__DIR__ . '/cache');

// Reset the counter so repeated runs are deterministic.
$cache->delete('views:post:1');

// Ask before you call, if your backend is pluggable.
assert($cache->supports(LockingStore::class));
assert($cache->supports(AtomicStore::class));

// ── Run a critical section under a lock ──────────────────────────────────────
$lock = $cache->lock('rebuild-report', 30);
if ($lock->acquire()) {
    try {
        echo 'report rebuilt at ' . date('H:i:s') . PHP_EOL;
    } finally {
        $lock->release();
    }
}

// ── Wait up to 5s for a contended lock, then run ─────────────────────────────
$invoice = $cache->lock('invoice:42', 30);
if ($invoice->block(5.0)) {
    try {
        echo "invoice 42 charged\n";
    } finally {
        $invoice->release();
    }
} else {
    echo "could not acquire the lock in time\n";
}

// Lock names are namespaced by scope, so two tenants running the same job name
// do not block each other.
$a = $cache->in('tenant-a')->lock('nightly-import', 30);
$b = $cache->in('tenant-b')->lock('nightly-import', 30);
assert($a->acquire() && $b->acquire());
$a->release();
$b->release();
echo "per-scope locks are independent\n";

// ── Atomic counters ──────────────────────────────────────────────────────────
$cache->increment('views:post:1', 1, initial: 0);   // → 1
$cache->increment('views:post:1', 10);              // → 11
$cache->decrement('views:post:1', 3);               // → 8

echo 'views:post:1 = ' . $cache->get('views:post:1') . PHP_EOL;
assert($cache->get('views:post:1') === 8);

// Under concurrency every increment is applied exactly once — no lost updates.
echo "OK\n";
