<?php

/**
 * Example 14 — add() conditional put and corrected semantics (v5.0.0)
 *
 * add() stores a value only when the key does not already exist.
 * The return value was inverted in v4.x; v5.0.0 fixes it:
 *
 *   add() returns TRUE  → key was new, value was stored.
 *   add() returns FALSE → key already existed, nothing was written.
 *
 * This matches the behaviour of memcached ADD, Laravel Cache::add(), and
 * every other mainstream caching library.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Silviooosilva\CacheerPhp\Cacheer;

$Cacheer = new Cacheer();
$Cacheer->setDriver()->useArrayDriver();

// ── 1. add() on a fresh key — returns true ─────────────────────────────────────

$stored = $Cacheer->add('config:theme', 'dark', ttl: 3600);

echo '--- add() on fresh key ---' . PHP_EOL;
echo 'Stored  : ' . var_export($stored, true) . PHP_EOL;             // true
echo 'Value   : ' . $Cacheer->getCache('config:theme') . PHP_EOL;    // dark

// ── 2. add() on an existing key — returns false, value unchanged ───────────────

$overwritten = $Cacheer->add('config:theme', 'light');   // key already exists

echo PHP_EOL . '--- add() on existing key ---' . PHP_EOL;
echo 'Stored  : ' . var_export($overwritten, true) . PHP_EOL;        // false
echo 'Value   : ' . $Cacheer->getCache('config:theme') . PHP_EOL;    // still dark

// ── 3. Practical: distributed lock / "first writer wins" ──────────────────────

echo PHP_EOL . '--- First-writer-wins pattern ---' . PHP_EOL;

$lock = 'job:send_invoice:42';

if ($Cacheer->add($lock, getmypid(), ttl: 60)) {
    echo 'Lock acquired by PID ' . getmypid() . ' — running job.' . PHP_EOL;
    // ... do the work ...
    $Cacheer->clearCache($lock);
} else {
    $owner = $Cacheer->getCache($lock);
    echo "Lock already held by PID {$owner} — skipping." . PHP_EOL;
}

// Simulate a second worker trying to acquire the same lock.
$Cacheer->add($lock, 9999, ttl: 60);  // will be rejected because the lock was re-acquired

// ── 4. add() with DateInterval TTL ────────────────────────────────────────────

$Cacheer->add('rate_limit:user:99', 0, ttl: new DateInterval('PT1M'));  // 1 minute
$Cacheer->increment('rate_limit:user:99', 1);  // count the request

echo PHP_EOL . '--- rate-limit counter ---' . PHP_EOL;
echo 'Requests this minute: ' . $Cacheer->getCache('rate_limit:user:99') . PHP_EOL;  // 1
