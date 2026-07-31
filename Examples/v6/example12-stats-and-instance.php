<?php

declare(strict_types=1);

/**
 * Example 12 — Inspecting what the cache is doing (v6)
 *
 * v5 exposed stats()/setInstance()/resetInstance()/getCacheStore() to inspect
 * and swap a global static singleton. v6 is instance-first: there is no global
 * singleton, so setInstance()/resetInstance() are gone by design — you simply
 * construct the cache you want and inject it.
 *
 * The useful half of v5's stats() — "what is my cache actually doing?" — is now
 * first-class observability:
 *   - MetricsCollector on an EventBus  → hits, misses, writes, hit rate.
 *   - InspectableStore::entries()      → walk the live keyspace.
 *
 * Run: php Examples/v6/example12-stats-and-instance.php
 */

require __DIR__ . '/../../vendor/autoload.php';

use Silviooosilva\CacheerPhp\Cacheer;
use Silviooosilva\CacheerPhp\Observability\EventBus;
use Silviooosilva\CacheerPhp\Observability\MetricsCollector;
use Silviooosilva\CacheerPhp\Stores\ArrayStore;
use Silviooosilva\CacheerPhp\Support\SystemClock;

$clock = new SystemClock();
$store = new ArrayStore($clock);

$metrics = new MetricsCollector();
$events = new EventBus();
$events->listen($metrics->record(...));

// Instrumented cache: every operation emits a typed event to the bus.
$cache = Cacheer::instrumented($store, $events);

$cache->set('user:1', ['name' => 'Ada']);
$cache->set('user:2', ['name' => 'Linus']);
$cache->get('user:1');   // hit
$cache->get('user:404'); // miss

// ── stats(), the v6 way ──────────────────────────────────────────────────────
$snapshot = $metrics->snapshot();
echo "--- metrics snapshot ---\n";
echo 'Writes   : ' . $snapshot['writes'] . PHP_EOL;
echo 'Hits     : ' . $snapshot['hits'] . PHP_EOL;
echo 'Misses   : ' . $snapshot['misses'] . PHP_EOL;
echo 'Hit rate : ' . $snapshot['hit_rate'] . PHP_EOL;

assert($snapshot['writes'] >= 2);
assert($snapshot['hits'] >= 1);
assert($snapshot['misses'] >= 1);

// ── Walk the live keyspace (InspectableStore) ────────────────────────────────
echo "--- live entries ---\n";
$keys = [];
foreach ($store->entries() as $entry) {
    $keys[] = $entry->key()->value();
}
sort($keys);
print_r($keys);
assert(in_array('user:1', $keys, true));

echo "OK\n";
