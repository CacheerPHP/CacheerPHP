<?php

declare(strict_types=1);

/**
 * v6 quick start: an in-process cache in five lines.
 *
 * Run: php examples/v6/01-quick-start.php
 */

require __DIR__ . '/../../vendor/autoload.php';

use Silviooosilva\CacheerPhp\Kernel\Cache;

$cache = Cache::inMemory();

$cache->set('greeting', 'hello world', ttl: 60);
assert($cache->get('greeting') === 'hello world');
assert($cache->has('greeting') === true);

// remember() computes once and serves the cached value afterwards.
$calls = 0;
$compute = function () use (&$calls): int {
    $calls++;

    return 41 + 1;
};

assert($cache->remember('answer', 60, $compute) === 42);
assert($cache->remember('answer', 60, $compute) === 42);
assert($calls === 1);

$cache->delete('greeting');
assert($cache->has('greeting') === false);

echo "OK\n";
