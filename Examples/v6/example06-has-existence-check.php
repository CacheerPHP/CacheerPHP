<?php

declare(strict_types=1);

/**
 * Example 06 — Existence checks with has() (v6)
 *
 * v5 → v6 mapping:
 *   $c->has($key, $namespace)  →  $c->scope($namespace)->has($key)
 *   $c->isSuccess()            →  the boolean returned by has()
 *
 * has() reports presence without materializing the value. To distinguish a
 * cached false/null from a miss, read entry()->isHit() instead (see example 13).
 *
 * The v5 version used the Redis driver; this one uses the file store so it runs
 * without a Redis server. Swap in Cacheer::redis(...) unchanged.
 *
 * Run: php Examples/v6/example06-has-existence-check.php
 */

require __DIR__ . '/../../vendor/autoload.php';

use Silviooosilva\CacheerPhp\Cacheer;

$cache = Cacheer::file(__DIR__ . '/cache');

$userData = $cache->scope('userData');

$cacheKey = 'user_profile_1234';
$userProfile = [
    'id' => 1,
    'name' => 'Silvio Silva',
    'email' => 'gasparsilvio7@gmail.com',
    'role' => 'Developer',
];

$userData->set($cacheKey, $userProfile);

if ($userData->has($cacheKey)) {
    echo "User Profile Found:\n";
    print_r($userData->get($cacheKey));
} else {
    echo "Cache not found for {$cacheKey}\n";
}

assert($userData->has($cacheKey) === true);
assert($userData->has('does-not-exist') === false);

echo "OK\n";
