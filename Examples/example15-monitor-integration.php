<?php

/**
 * Example 15 — Cacheer Monitor Integration
 *
 * Install cacheer-monitor and telemetry is active automatically:
 *
 *   composer require cacheerphp/monitor
 *
 * No other changes required — the package self-registers via Composer's
 * autoload.files. Every cache call below emits an event to the JSONL
 * file consumed by the dashboard.
 */

require __DIR__ . '/../vendor/autoload.php';

use Silviooosilva\CacheerPhp\Cacheer;

// ── Nothing to configure — monitor is active as soon as autoload runs ─────

$cacheer = new Cacheer(['cacheDir' => __DIR__ . '/cache']);
$cacheer->setDriver()->useFileDriver();


$cacheer->putCache('user:1', ['name' => 'Ana Patricia',   'role'   => 'admin']);
$cacheer->putCache('user:2', ['name' => 'Silvio Silva',   'role'   => 'editor']);


$cacheer->getCache('user:1');   // → 'hit'
$cacheer->getCache('user:99');  // → 'miss'

$cacheer->increment('page_views');
$cacheer->clearCache('user:2');

Cacheer::/** @scrutinizer ignore-call */ putCache('config:locale', 'en_US');
Cacheer::/** @scrutinizer ignore-call */ getCache('config:locale');  // → 'hit'


// ── Start the dashboard (separate terminal) ────────────────────────────────
//   vendor/bin/cacheer-monitor serve --port=9966
//   → http://127.0.0.1:9966
// ──────────────────────────────────────────────────────────────────────────

echo "Cache operations completed." . PHP_EOL;
