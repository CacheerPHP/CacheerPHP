<?php

/**
 * Example 09 — PSR-16 SimpleCache adapter (v5.0.0)
 *
 * Psr16CacheAdapter wraps any Cacheer instance and exposes the standard
 * \Psr\SimpleCache\CacheInterface contract, so CacheerPHP can be injected
 * into any framework or library that expects a PSR-16 cache.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Silviooosilva\CacheerPhp\Cacheer;
use Silviooosilva\CacheerPhp\Psr\Psr16CacheAdapter;
use Silviooosilva\CacheerPhp\Exceptions\CacheInvalidArgumentException;

// ── 1. Wrap a Cacheer instance in the PSR-16 adapter ──────────────────────────

$cacheer = new Cacheer();

$psr     = new Psr16CacheAdapter($cacheer);

$options = $cacheer->getOption('expirationTime', 'expirationTime2');

// ── 2. set() / get() / has() / delete() ───────────────────────────────────────

$psr->set('greeting', 'Hello, PSR-16!', 3600);

echo $psr->get('greeting') . PHP_EOL;          // Hello, PSR-16!
echo var_export($psr->has('greeting'), true) . PHP_EOL;  // true

$psr->delete('greeting');
echo var_export($psr->has('greeting'), true) . PHP_EOL;  // false

// ── 3. Batch operations ────────────────────────────────────────────────────────

$psr->setMultiple([
    'user:1' => ['name' => 'Alice', 'role' => 'admin'],
    'user:2' => ['name' => 'Bob',   'role' => 'viewer'],
], 1800);

$users = $psr->getMultiple(['user:1', 'user:2', 'user:99'], 'NOT FOUND');

foreach ($users as $key => $value) {
    echo "$key => " . (is_array($value) ? $value['name'] : $value) . PHP_EOL;
}
// user:1 => Alice
// user:2 => Bob
// user:99 => NOT FOUND

$psr->deleteMultiple(['user:1', 'user:2']);

// ── 4. Null TTL — store forever ────────────────────────────────────────────────

$psr->set('app_version', 'v5.0.0', null);   // null → PHP_INT_MAX (forever)
echo $psr->get('app_version') . PHP_EOL;     // v5.0.0

// ── 5. DateInterval TTL ────────────────────────────────────────────────────────

$psr->set('session_token', bin2hex(random_bytes(16)), new DateInterval('PT30M')); // 30 minutes
echo $psr->get('session_token') . PHP_EOL;

// ── 6. Key validation — PSR-16 reserved characters throw immediately ───────────

try {
    $psr->get('bad:key');
} catch (CacheInvalidArgumentException $e) {
    echo 'Invalid key: ' . $e->getMessage() . PHP_EOL;
}

// ── 7. Namespace isolation via constructor ─────────────────────────────────────

$nsA = new Psr16CacheAdapter($cacheer, 'moduleA');
$nsB = new Psr16CacheAdapter($cacheer, 'moduleB');

$nsA->set('config', 'A-value');
$nsB->set('config', 'B-value');

echo $nsA->get('config') . PHP_EOL;  // A-value
echo $nsB->get('config') . PHP_EOL;  // B-value

// Clean up
$psr->clear();
