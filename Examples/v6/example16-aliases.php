<?php

declare(strict_types=1);

/**
 * Example 16 — The core verbs replace v5's aliases (v6)
 *
 * v5 shipped convenience aliases; v6 keeps one obvious verb for each job:
 *   forget()  → delete()
 *   missing() → ! has()
 *   pull()    → get() then delete()   (a two-liner; wrap in a lock for atomicity)
 *
 * Run: php Examples/v6/example16-aliases.php
 */

require __DIR__ . '/../../vendor/autoload.php';

use Silviooosilva\CacheerPhp\Cacheer;

$cache = Cacheer::file(__DIR__ . '/cache');

// ── "missing" — inverse of has() ─────────────────────────────────────────────
$cache->set('greeting', 'hello');
var_dump(!$cache->has('greeting'));      // false — key is present
var_dump(!$cache->has('never-stored'));  // true  — key is absent

// ── "forget" — delete() ──────────────────────────────────────────────────────
$cache->delete('greeting');
var_dump(!$cache->has('greeting'));      // true now

// ── "pull" — read once, then remove ──────────────────────────────────────────
$cache->set('flash-message', 'Saved successfully!');

$pull = static function (Cacheer $c, string $key): mixed {
    $value = $c->get($key);
    $c->delete($key);

    return $value;
};

echo $pull($cache, 'flash-message') . PHP_EOL;   // Saved successfully!
var_dump(!$cache->has('flash-message'));          // true — pull removed it
var_dump($pull($cache, 'never-stored'));          // NULL on a miss

assert($cache->has('flash-message') === false);

echo "OK\n";
