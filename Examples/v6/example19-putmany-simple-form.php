<?php

declare(strict_types=1);

/**
 * Example 19 — Bulk writes with setMany() (v6)
 *
 * v5 → v6 mapping:
 *   $c->putMany(['k' => $v, ...])                →  $c->setMany(['k' => $v, ...])
 *   $c->putMany([...], $namespace)               →  $c->scope($namespace)->setMany([...])
 *
 * v6 takes a single, obvious shape: an associative array of key => value. (The
 * legacy [['cacheKey' => k, 'cacheData' => v], ...] shape from v4/v5 is gone.)
 * many() reads several keys back in one call.
 *
 * Run: php Examples/v6/example19-putmany-simple-form.php
 */

require __DIR__ . '/../../vendor/autoload.php';

use Silviooosilva\CacheerPhp\Cacheer;

$cache = Cacheer::file(__DIR__ . '/cache');

// ── Associative bulk write ───────────────────────────────────────────────────
$cache->setMany([
    'user.1' => ['name' => 'Alice', 'age' => 30],
    'user.2' => ['name' => 'Bob', 'age' => 27],
    'user.3' => ['name' => 'Carol', 'age' => 41],
]);

print_r($cache->get('user.1'));
assert($cache->get('user.2') === ['name' => 'Bob', 'age' => 27]);

// ── Read several keys back at once ───────────────────────────────────────────
$found = $cache->many(['user.1', 'user.2', 'user.404'], default: 'MISSING');
echo 'user.404 => ' . $found['user.404'] . PHP_EOL; // MISSING
assert($found['user.1']['name'] === 'Alice');

// ── Bulk write into a scope ──────────────────────────────────────────────────
$cache->scope('metrics')->setMany([
    'x' => ['count' => 10],
    'y' => ['count' => 20],
    'z' => ['count' => 30],
]);

print_r($cache->scope('metrics')->get('y')); // ['count' => 20]
assert($cache->scope('metrics')->get('y') === ['count' => 20]);
assert($cache->get('y') === null); // scoped writes stay in their scope

echo "OK\n";
