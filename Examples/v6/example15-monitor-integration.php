<?php

declare(strict_types=1);

/**
 * Example 15 — Cacheer Monitor integration (v6)
 *
 * The cacheer-monitor package plugs into v6's observability layer: it listens on
 * an EventBus and records each cache operation for its dashboard.
 *
 *   composer require cacheerphp/monitor
 *   vendor/bin/cacheer-monitor serve --port=9966   # → http://127.0.0.1:9966
 *
 * You attach it by wrapping your store with Cacheer::instrumented($store, $bus)
 * and registering the monitor's listener on $bus. This example uses a tiny
 * inline listener to show the exact hook the monitor builds on — swap it for the
 * package's listener and telemetry flows to the dashboard unchanged.
 *
 * Run: php Examples/v6/example15-monitor-integration.php
 */

require __DIR__ . '/../../vendor/autoload.php';

use Silviooosilva\CacheerPhp\Cacheer;
use Silviooosilva\CacheerPhp\Observability\EventBus;
use Silviooosilva\CacheerPhp\Stores\FileStore;
use Silviooosilva\CacheerPhp\Support\SystemClock;

$clock = new SystemClock();
$store = new FileStore(__DIR__ . '/cache', clock: $clock);

$events = new EventBus();

// Stand-in for the monitor listener: append every event as one JSONL line.
$log = [];
$events->listen(function (object $event) use (&$log): void {
    $log[] = json_encode([
        'type' => $event::class,
        'at' => microtime(true),
    ], JSON_UNESCAPED_SLASHES);
});

$cache = Cacheer::instrumented($store, $events);

$cache->set('user:1', ['name' => 'Ana Patricia', 'role' => 'admin']);
$cache->set('user:2', ['name' => 'Silvio Silva', 'role' => 'editor']);
$cache->get('user:1');   // hit
$cache->get('user:99');  // miss
$cache->delete('user:2');

echo 'Events captured: ' . count($log) . PHP_EOL;
echo "First event line:\n  " . $log[0] . PHP_EOL;
assert(count($log) >= 5);

echo "Cache operations completed.\n";
echo "OK\n";
