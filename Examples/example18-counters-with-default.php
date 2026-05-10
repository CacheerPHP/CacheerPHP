<?php

/**
 * Example 18 — Counters with optional default and TTL
 *
 * `increment()` and `decrement()` accept two optional parameters that opt
 * into create-on-miss behaviour without breaking legacy callers:
 *
 *   increment(string $key, int $amount = 1, string $namespace = '',
 *             ?int $default = null,
 *             int|string|\DateInterval|null $ttl = null): bool
 *
 *   - $default = null  → legacy behaviour (return false if the key is absent).
 *   - $default given   → if the key is absent, store ($default + $amount) and
 *                        apply the optional $ttl.
 *
 * Falsy stored values (notably 0) are real cache hits, so calling
 * `increment('counter')` on a stored 0 still works.
 */

require_once __DIR__ . "/../vendor/autoload.php";

use Silviooosilva\CacheerPhp\Cacheer;
use Silviooosilva\CacheerPhp\Config\Option\Builder\OptionBuilder;

$options = OptionBuilder::forFile()
    ->dir(__DIR__ . "/cache")
    ->build();

$Cacheer = new Cacheer($options);

// --- Legacy path: missing key + no default → false ----------------------
$ok = $Cacheer->increment("legacy-counter");
var_dump($ok);                                          // false
var_dump($Cacheer->has("legacy-counter"));              // false — nothing was written

// --- Create-on-miss with an explicit default ----------------------------
$Cacheer->increment("page-views", 1, "", 0);
echo $Cacheer->getCache("page-views") . PHP_EOL;        // 1

$Cacheer->increment("page-views", 1, "", 0);
echo $Cacheer->getCache("page-views") . PHP_EOL;        // 2

// --- Default + amount when seeding from a non-zero base -----------------
$Cacheer->increment("budget", 10, "", 100);
echo $Cacheer->getCache("budget") . PHP_EOL;            // 110 (= 100 + 10)

// --- decrement() shares the same signature ------------------------------
$Cacheer->decrement("stock", 5, "", 100);
echo $Cacheer->getCache("stock") . PHP_EOL;             // 95 (= 100 - 5)

// --- Time-bounded counter (rate-limit style) ----------------------------
$Cacheer->increment("rate-window", 1, "", 0, "1 minute");
//  → 'rate-window' is created with TTL 60s. Subsequent increments within
//    the window keep ticking; after expiry, the next call seeds a new 0+1=1.

// --- Falsy-value support: incrementing a stored 0 still works -----------
$Cacheer->putCache("zero", 0);
$Cacheer->increment("zero");
echo $Cacheer->getCache("zero") . PHP_EOL;              // 1
