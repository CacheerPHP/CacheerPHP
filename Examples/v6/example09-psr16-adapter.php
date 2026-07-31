<?php

declare(strict_types=1);

/**
 * Example 09 — PSR-16 (SimpleCache) adapter (v6)
 *
 * v5 → v6 mapping:
 *   new Psr16CacheAdapter($cacheer)  →  new Psr16Cache($cacheer)
 *
 * Psr16Cache wraps any Cacheer instance and exposes the standard
 * \Psr\SimpleCache\CacheInterface, so CacheerPHP drops into any framework or
 * library that expects a PSR-16 cache. It enforces PSR-16 key rules and TTL
 * semantics (null = keep forever; non-positive TTL deletes).
 *
 * Note: the v5 adapter took a per-instance namespace argument. In v6 you isolate
 * keyspaces with scopes at the kernel level (see example 04); the PSR-16 surface
 * itself is intentionally a flat keyspace, per the specification.
 *
 * Run: php Examples/v6/example09-psr16-adapter.php
 */

require __DIR__ . '/../../vendor/autoload.php';

use Silviooosilva\CacheerPhp\Cacheer;
use Silviooosilva\CacheerPhp\Exceptions\CacheInvalidArgumentException;
use Silviooosilva\CacheerPhp\Psr\Psr16Cache;

$psr = new Psr16Cache(Cacheer::inMemory());

// ── 1. set / get / has / delete ──────────────────────────────────────────────
$psr->set('greeting', 'Hello, PSR-16!', 3600);
echo $psr->get('greeting') . PHP_EOL;                      // Hello, PSR-16!
echo var_export($psr->has('greeting'), true) . PHP_EOL;    // true

$psr->delete('greeting');
echo var_export($psr->has('greeting'), true) . PHP_EOL;    // false

// ── 2. Batch operations ──────────────────────────────────────────────────────
// PSR-16 reserves {}()/\@: in keys, so use dots (not colons) as separators.
$psr->setMultiple([
    'user.1' => ['name' => 'Alice', 'role' => 'admin'],
    'user.2' => ['name' => 'Bob', 'role' => 'viewer'],
], 1800);

$users = $psr->getMultiple(['user.1', 'user.2', 'user.99'], 'NOT FOUND');
foreach ($users as $key => $value) {
    echo "$key => " . (is_array($value) ? $value['name'] : $value) . PHP_EOL;
}
// user.1 => Alice / user.2 => Bob / user.99 => NOT FOUND

$psr->deleteMultiple(['user.1', 'user.2']);

// ── 3. null TTL — store forever ──────────────────────────────────────────────
$psr->set('app_version', 'v6.0.0', null);
echo $psr->get('app_version') . PHP_EOL;                   // v6.0.0

// ── 4. DateInterval TTL ──────────────────────────────────────────────────────
$psr->set('session_token', bin2hex(random_bytes(16)), new DateInterval('PT30M'));
echo strlen($psr->get('session_token')) . " hex chars\n"; // 32

// ── 5. PSR-16 reserved characters are rejected ───────────────────────────────
try {
    $psr->get('bad{key}');
} catch (CacheInvalidArgumentException $e) {
    echo 'Invalid key rejected: ' . $e->getMessage() . PHP_EOL;
}

$psr->clear();

echo "OK\n";
