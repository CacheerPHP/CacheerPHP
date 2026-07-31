<?php

declare(strict_types=1);

/**
 * Example 14 — Conditional / first-writer-wins writes (v6)
 *
 * v5 had add() — "store only if the key is absent" — often used as a poor man's
 * distributed lock. v6 does not expose add(); the tiny store contract offers two
 * precise primitives instead:
 *
 *   - LockingStore::lock()          → a real cross-process mutex (use this for
 *                                     "must happen once" side effects).
 *   - AtomicStore::compareAndSwap() → swap a value only if it still equals what
 *                                     you last read (optimistic concurrency).
 *
 * For a plain single-process "set if absent", has() + set() is enough.
 *
 * Run: php Examples/v6/example14-add-conditional.php
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

// Reset keys this example writes, so repeated runs are deterministic.
$cache->delete('config:theme');
$cache->delete('rate_limit:user:99');

// ── 1. Set-if-absent (single process) ────────────────────────────────────────
if (!$cache->has('config:theme')) {
    $cache->set('config:theme', 'dark', ttl: 3600);
}
echo 'Theme: ' . $cache->get('config:theme') . PHP_EOL;   // dark

// A second attempt does not overwrite it.
if (!$cache->has('config:theme')) {
    $cache->set('config:theme', 'light');
}
echo 'Theme still: ' . $cache->get('config:theme') . PHP_EOL; // dark
assert($cache->get('config:theme') === 'dark');

// ── 2. First-writer-wins across processes (LockingStore) ─────────────────────
$lock = $store->lock('job:send_invoice:42', Ttl::seconds(60));
if ($lock->acquire()) {
    try {
        echo 'Lock acquired by PID ' . getmypid() . " — running job.\n";
        // ... do the once-only work ...
    } finally {
        $lock->release();
    }
} else {
    echo "Another worker already holds the lock — skipping.\n";
}

// ── 3. Rate-limit counter — create-on-miss with a TTL window ─────────────────
$store->increment(Key::named('rate_limit:user:99'), 1, initial: 0, ttl: Ttl::minutes(1));
echo 'Requests this minute: ' . $cache->get('rate_limit:user:99') . PHP_EOL; // 1
assert($cache->get('rate_limit:user:99') === 1);

echo "OK\n";
