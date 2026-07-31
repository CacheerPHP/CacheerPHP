<?php

declare(strict_types=1);

/**
 * Example 05 — Cache an API response (v6)
 *
 * v5 used getCache()/isSuccess()/putCache() around the HTTP call. In v6 the
 * whole "read-through" pattern collapses into a single remember(): the callback
 * only runs on a miss, and its result is cached for the given TTL.
 *
 * Run: php Examples/v6/example05-cache-api-response.php
 */

require __DIR__ . '/../../vendor/autoload.php';

use Silviooosilva\CacheerPhp\Cacheer;

$cache = Cacheer::file(__DIR__ . '/cache');

$apiUrl = 'https://jsonplaceholder.typicode.com/posts';
$cacheKey = 'api_response_' . md5($apiUrl);

// Fetch once, then serve from cache for 10 minutes. The closure runs only when
// the key is cold (and only once, even under concurrent requests).
$response = $cache->remember($cacheKey, '10 minutes', function () use ($apiUrl): string {
    $body = @file_get_contents($apiUrl);

    // Fall back to a small fixture when the network is unavailable, so the
    // example stays runnable offline.
    return $body !== false
        ? $body
        : json_encode([['id' => 1, 'title' => 'offline fixture']], JSON_THROW_ON_ERROR);
});

$data = json_decode($response, true);
echo 'Posts loaded: ' . count($data) . PHP_EOL;

assert(is_array($data) && $data !== []);

echo "OK\n";
