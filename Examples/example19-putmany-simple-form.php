<?php

/**
 * Example 19 — putMany() accepts a simple associative array
 *
 * The legacy [['cacheKey' => k, 'cacheData' => v], ...] shape continues to
 * work unchanged. The simple ['k' => $v] form is normalised internally.
 *
 * Both shapes can be mixed in a single call and combined with namespaces.
 */

require_once __DIR__ . "/../vendor/autoload.php";

use Silviooosilva\CacheerPhp\Cacheer;
use Silviooosilva\CacheerPhp\Config\Option\Builder\OptionBuilder;

$options = OptionBuilder::forFile()
    ->dir(__DIR__ . "/cache")
    ->build();

$Cacheer = new Cacheer($options);

// --- Simple associative form --------------------------------------------
$Cacheer->putMany([
    "user.1" => ["name" => "Alice", "age" => 30],
    "user.2" => ["name" => "Bob",   "age" => 27],
    "user.3" => ["name" => "Carol", "age" => 41],
]);

print_r($Cacheer->getCache("user.1"));
//  → ['name' => 'Alice', 'age' => 30]

// --- Legacy explicit form still works -----------------------------------
$Cacheer->putMany([
    ["cacheKey" => "feature.search-v2",  "cacheData" => ["enabled" => true]],
    ["cacheKey" => "feature.dark-theme", "cacheData" => ["enabled" => false]],
]);

print_r($Cacheer->getCache("feature.search-v2"));
//  → ['enabled' => true]

// --- Mixed shapes in the same call --------------------------------------
$Cacheer->putMany([
    "session.alpha"                                            => ["uid" => 1001],
    ["cacheKey" => "session.beta",  "cacheData" => ["uid" => 1002]],
    "session.gamma"                                            => ["uid" => 1003],
]);

foreach (["session.alpha", "session.beta", "session.gamma"] as $key) {
    print_r($Cacheer->getCache($key));
}

// --- Bulk-write into a namespace ----------------------------------------
$Cacheer->putMany([
    "x" => ["count" => 10],
    "y" => ["count" => 20],
    "z" => ["count" => 30],
], "metrics");

print_r($Cacheer->in("metrics")->get("y")); // ['count' => 20]
