<?php

/**
 * Example 16 — Convenience aliases: forget(), pull(), missing()
 *
 * Three short-name wrappers over existing methods:
 *   - forget()  → clearCache()
 *   - pull()    → getAndForget()  (atomic get + delete)
 *   - missing() → !has()
 */

require_once __DIR__ . "/../vendor/autoload.php";

use Silviooosilva\CacheerPhp\Cacheer;
use Silviooosilva\CacheerPhp\Config\Option\Builder\OptionBuilder;

$options = OptionBuilder::forFile()
    ->dir(__DIR__ . "/cache")
    ->build();

$Cacheer = new Cacheer($options);

// --- missing() — inverse of has() ----------------------------------------
$Cacheer->putCache("greeting", "hello");

var_dump($Cacheer->missing("greeting"));     // false — key is present
var_dump($Cacheer->missing("never-stored")); // true  — key is absent

// --- forget() — alias of clearCache() ------------------------------------
$Cacheer->forget("greeting");
var_dump($Cacheer->missing("greeting"));     // true now

// --- pull() — atomic get + delete ----------------------------------------
$Cacheer->putCache("flash-message", "Saved successfully!");

$message = $Cacheer->pull("flash-message");
echo $message . PHP_EOL;                     // "Saved successfully!"
var_dump($Cacheer->missing("flash-message")); // true — pull() removed it

// pull() returns null on miss instead of throwing
var_dump($Cacheer->pull("never-stored"));    // NULL
