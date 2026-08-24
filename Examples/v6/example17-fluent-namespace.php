<?php

declare(strict_types=1);

/**
 * Example 17 — Scoped, chainable keyspaces (v6)
 *
 * v5 → v6 mapping:
 *   $c->in('users')->put('123', $v)        →  $c->scope('users')->set('123', $v)
 *   $c->in('users')->in('123')             →  $c->scope('users')->scope('123')
 *   $c->getNamespace()                     →  $scoped->boundScope()
 *   $c->withoutNamespace()                 →  use the root $cache instance
 *
 * scope() returns a new immutable Cacheer, so chaining never mutates the
 * parent. remember() honors the bound scope.
 *
 * Run: php Examples/v6/example17-fluent-namespace.php
 */

require __DIR__ . '/../../vendor/autoload.php';

use Silviooosilva\CacheerPhp\Cacheer;

$cache = Cacheer::file(__DIR__ . '/cache');

// ── A bound scope ────────────────────────────────────────────────────────────
$cache->scope('users')->set('123', ['name' => 'Alice', 'age' => 30]);
print_r($cache->scope('users')->get('123'));

// ── Nested scopes ────────────────────────────────────────────────────────────
$cache->scope('users')->scope('123')->set('profile', ['bio' => 'PHP developer']);
$nested = $cache->scope('users')->scope('123')->get('profile');
assert($nested === ['bio' => 'PHP developer']);

// ── Immutability: chaining does not mutate the parent ────────────────────────
$users = $cache->scope('users');
$admins = $users->scope('admins'); // does NOT change $users

echo 'users scope : ' . $users->boundScope() . PHP_EOL;   // users
echo 'admins scope: ' . $admins->boundScope() . PHP_EOL;  // users.admins

// ── Escape a scope by using the root instance ────────────────────────────────
$tenant = $cache->scope('tenant-a');
$tenant->set('config', ['timezone' => 'UTC']);
$cache->set('shared-flag', true); // stored at the root, not under tenant-a

assert($tenant->get('shared-flag') === null);
assert($cache->get('shared-flag') === true);

// ── remember() honors the bound scope ────────────────────────────────────────
$value = $cache->scope('reports')->remember('daily-summary', 3600, function (): array {
    return ['orders' => 1287, 'revenue' => 42850.50];
});
print_r($value);
assert($value['orders'] === 1287);

echo "OK\n";
