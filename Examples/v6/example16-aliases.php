<?php

declare(strict_types=1);

/**
 * Example 16 — The v5 convenience verbs, kept (v6)
 *
 * v5's small ergonomic vocabulary survives the rewrite. Some names changed to
 * the ones the rest of the API uses; the reading they gave you did not:
 *
 *   forget()          → delete()
 *   clearCache()      → delete()
 *   flushCache()      → clear()
 *   missing()         → missing()          (kept — reads better in a guard)
 *   pull()            → pull()             (kept — read-and-remove in one call)
 *   getAndForget()    → pull()
 *   forever()         → forever()          (kept)
 *   rememberForever() → rememberForever()  (kept)
 *
 * Run: php Examples/v6/example16-aliases.php
 */

require __DIR__ . '/../../vendor/autoload.php';

use Silviooosilva\CacheerPhp\Cacheer;

$cache = Cacheer::file(__DIR__ . '/cache');
$cache->clear();

// ── missing() — the inverse of has(), for guard clauses ──────────────────────
$cache->set('greeting', 'hello');
var_dump($cache->missing('greeting'));      // false — key is present
var_dump($cache->missing('never-stored'));  // true  — key is absent

// ── delete() — v5's forget()/clearCache() ────────────────────────────────────
$cache->delete('greeting');
var_dump($cache->missing('greeting'));      // true now

// ── pull() — read once, then remove ──────────────────────────────────────────
$cache->set('flash-message', 'Saved successfully!');

echo $cache->pull('flash-message') . PHP_EOL;   // Saved successfully!
var_dump($cache->missing('flash-message'));      // true — pull removed it
var_dump($cache->pull('never-stored'));          // NULL on a miss
var_dump($cache->pull('never-stored', 'none'));  // your default instead

// pull() reports a stored null as the value it is, not as a miss.
$cache->set('nullable', null);
var_dump($cache->pull('nullable', 'default'));   // NULL   — the stored value
var_dump($cache->pull('nullable', 'default'));   // 'default' — now really gone

// ── forever() / rememberForever() — no expiry, stated plainly ────────────────
$cache->forever('app:config', ['theme' => 'dark']);
$version = $cache->rememberForever('app:version', static fn (): string => '6.0.0');

echo 'config: ' . json_encode($cache->get('app:config')) . PHP_EOL;
echo 'version: ' . $version . PHP_EOL;

assert($cache->missing('flash-message'));
assert($cache->get('app:config') === ['theme' => 'dark']);

echo "OK\n";
