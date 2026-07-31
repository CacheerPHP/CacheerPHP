<?php

declare(strict_types=1);

/**
 * Example 03 — Delete a key and clear the whole cache (v6)
 *
 * v5 → v6 mapping:
 *   $c->clearCache($key)  →  $c->delete($key)   (remove one entry)
 *   $c->flushCache()      →  $c->clear()        (empty the current scope)
 *
 * Both return plain values (delete(): bool, clear(): void) instead of the old
 * isSuccess()/getMessage() status pair.
 *
 * Run: php Examples/v6/example03-clear-and-flush.php
 */

require __DIR__ . '/../../vendor/autoload.php';

use Silviooosilva\CacheerPhp\Cacheer;

$cache = Cacheer::file(__DIR__ . '/cache');

$cache->set('user_profile_123', ['id' => 123]);
$cache->set('user_profile_456', ['id' => 456]);

// Remove a single entry.
$deleted = $cache->delete('user_profile_123');
echo 'Deleted user_profile_123: ' . var_export($deleted, true) . PHP_EOL;
assert($cache->has('user_profile_123') === false);
assert($cache->has('user_profile_456') === true);

// Empty everything in this scope.
$cache->clear();
echo "Cache cleared.\n";
assert($cache->has('user_profile_456') === false);

echo "OK\n";
