<?php

declare(strict_types=1);

/**
 * Example 01 — Simple put and get (v6)
 *
 * v5 → v6 mapping:
 *   new Cacheer(OptionBuilder::forFile()->dir(...)->build())  →  Cacheer::file(dir)
 *   $c->putCache($key, $value)                                →  $c->set($key, $value)
 *   $c->getCache($key)                                        →  $c->get($key)
 *   $c->isSuccess()                                           →  $c->has($key) / entry()->isHit()
 *
 * Run: php Examples/v6/example01-simple-put-get.php
 */

require __DIR__ . '/../../vendor/autoload.php';

use Silviooosilva\CacheerPhp\Cacheer;

$cache = Cacheer::file(__DIR__ . '/cache');

$cacheKey = 'user_profile_1234';
$userProfile = [
    'id' => 123,
    'name' => 'John Doe',
    'email' => 'john.doe@example.com',
];

// Store, then read back.
$cache->set($cacheKey, $userProfile);

$cachedProfile = $cache->get($cacheKey);

if ($cache->has($cacheKey)) {
    echo "Cache Found: ";
    print_r($cachedProfile);
} else {
    echo "Cache miss for {$cacheKey}\n";
}

assert($cachedProfile === $userProfile);

echo "OK\n";
