<?php

declare(strict_types=1);

/**
 * v6 scopes plus observability: isolate keys per feature and watch what the
 * cache is doing through typed events and a metrics collector.
 *
 * Run: php examples/v6/02-scopes-and-observability.php
 */

require __DIR__ . '/../../vendor/autoload.php';

use Silviooosilva\CacheerPhp\Cacheer;
use Silviooosilva\CacheerPhp\Observability\EventBus;
use Silviooosilva\CacheerPhp\Observability\MetricsCollector;
use Silviooosilva\CacheerPhp\Stores\ArrayStore;
use Silviooosilva\CacheerPhp\Support\SystemClock;

$clock = new SystemClock();

$metrics = new MetricsCollector();
$events = new EventBus();
$events->listen($metrics->record(...));

$cache = Cacheer::instrumented(new ArrayStore($clock), $events);

// Scopes keep the same key name isolated per feature.
$cache->scope('reports')->set('daily', ['rows' => 10]);
$cache->scope('billing')->set('daily', ['rows' => 99]);

assert($cache->scope('reports')->get('daily') === ['rows' => 10]);
assert($cache->scope('billing')->get('daily') === ['rows' => 99]);
assert($cache->get('daily') === null); // root scope is a different keyspace

// A miss then a hit, so the metrics snapshot has something to show.
$cache->get('missing');
$cache->set('warm', true);
$cache->get('warm');

$snapshot = $metrics->snapshot();
assert($snapshot['writes'] >= 3);
assert($snapshot['hits'] >= 3);
assert($snapshot['misses'] >= 1);

echo 'hit_rate=' . $snapshot['hit_rate'] . "\n";
echo "OK\n";
