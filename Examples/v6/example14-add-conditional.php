<?php

declare(strict_types=1);

/**
 * Example 14 — Conditional / first-writer-wins writes (v6)
 *
 * v5's add() — "store only if the key is absent" — is back on the cache in v6,
 * and it is no longer the racy has()+set() pair you would otherwise write by
 * hand: when the store can lock, add() serializes the check and the write, so
 * exactly one caller wins across processes. When it cannot, it degrades to a
 * single-process check and says so here.
 *
 * For "must happen once" side effects, take a real lock instead — add() protects
 * the cache entry, a lock protects your work.
 *
 * Run: php Examples/v6/example14-add-conditional.php
 */

require __DIR__ . '/../../vendor/autoload.php';

use Silviooosilva\CacheerPhp\Cacheer;
use Silviooosilva\CacheerPhp\Contracts\LockingStore;

$cache = Cacheer::file(__DIR__ . '/cache');

// Reset keys this example writes, so repeated runs are deterministic.
$cache->deleteMany(['config:theme', 'rate_limit:user:99']);

// ── 1. Set-if-absent ─────────────────────────────────────────────────────────
$stored = $cache->add('config:theme', 'dark', ttl: 3600);
echo 'stored: ' . var_export($stored, true) . ' → ' . $cache->get('config:theme') . PHP_EOL;
assert($stored === true);

// A second attempt does not overwrite it, and says so.
$stored = $cache->add('config:theme', 'light');
echo 'stored again: ' . var_export($stored, true) . ' → ' . $cache->get('config:theme') . PHP_EOL;
assert($stored === false);
assert($cache->get('config:theme') === 'dark');

echo 'add() is cross-process safe here: '
    . var_export($cache->supports(LockingStore::class), true) . PHP_EOL;

// ── 2. First-writer-wins across processes, for side effects ──────────────────
$lock = $cache->lock('job:send_invoice:42', 60);
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
$cache->increment('rate_limit:user:99', 1, initial: 0, ttl: '1 minute');
echo 'Requests this minute: ' . $cache->get('rate_limit:user:99') . PHP_EOL;
assert($cache->get('rate_limit:user:99') === 1);

echo "OK\n";
