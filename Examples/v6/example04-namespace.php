<?php

declare(strict_types=1);

/**
 * Example 04 — Namespaces are now scopes (v6)
 *
 * v5 → v6 mapping:
 *   $c->putCache($key, $value, $namespace)  →  $c->scope($namespace)->set($key, $value)
 *   $c->getCache($key, $namespace)          →  $c->scope($namespace)->get($key)
 *
 * scope() returns a new immutable Cacheer that shares the same store but a
 * separate keyspace, so identical key names never collide across scopes.
 *
 * Run: php Examples/v6/example04-namespace.php
 */

require __DIR__ . '/../../vendor/autoload.php';

use Silviooosilva\CacheerPhp\Cacheer;

$cache = Cacheer::file(__DIR__ . '/cache');

$sessionData = [
    'user_id' => 456,
    'login_time' => time(),
];

$sessions = $cache->scope('session_data_01');
$sessions->set('session_456', $sessionData);

$cachedSessionData = $sessions->get('session_456');

echo "Cache Found (scope 'session_data_01'): ";
print_r($cachedSessionData);

assert($cachedSessionData === $sessionData);

// The same key in the root keyspace is a different entry.
assert($cache->get('session_456') === null);

echo "OK\n";
