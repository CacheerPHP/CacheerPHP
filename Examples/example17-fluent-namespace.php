<?php

/**
 * Example 17 — Fluent namespace context with PendingCache
 *
 * `in()`, `namespace()`, and `withoutNamespace()` return an immutable
 * `PendingCache` wrapper so the namespace travels with the chain instead of
 * being passed as a positional argument on every call.
 *
 * Dot notation is supported: `in('users.123')` and `in('users')->in('123')`
 * produce the same namespace.
 */

require_once __DIR__ . "/../vendor/autoload.php";

use Silviooosilva\CacheerPhp\Cacheer;
use Silviooosilva\CacheerPhp\Config\Option\Builder\OptionBuilder;

$options = OptionBuilder::forFile()
    ->dir(__DIR__ . "/cache")
    ->build();

$Cacheer = new Cacheer($options);

// --- A simple bound namespace --------------------------------------------
$Cacheer->in("users")->put("123", ["name" => "Alice", "age" => 30]);

print_r($Cacheer->in("users")->get("123"));
//  → ['name' => 'Alice', 'age' => 30]

// `namespace()` is the long-form alias of `in()`:
$Cacheer->namespace("users")->put("124", ["name" => "Bob"]);

// --- Dot notation: hierarchical namespaces -------------------------------
$Cacheer->in("users.123")->put("profile", ["bio" => "PHP developer"]);
$flat   = $Cacheer->in("users.123")->get("profile");

// equivalent chained form:
$nested = $Cacheer->in("users")->in("123")->get("profile");

var_dump($flat === $nested); // true — both point to the same entry

// --- Immutability --------------------------------------------------------
$users  = $Cacheer->in("users");
$admins = $users->in("admins"); // does NOT mutate $users

echo $users->getNamespace() . PHP_EOL;  // "users"
echo $admins->getNamespace() . PHP_EOL; // "users.admins"

// --- withoutNamespace() to escape a chain --------------------------------
$tenant = $Cacheer->in("tenant-a");
$tenant->put("config", ["timezone" => "UTC"]);

$tenant->withoutNamespace()->put("shared-flag", true);
//  → 'shared-flag' is stored at the root, NOT under 'tenant-a'.

var_dump($Cacheer->getCache("shared-flag", "tenant-a")); // null
var_dump($Cacheer->getCache("shared-flag"));             // true

// --- remember() honours the bound namespace ------------------------------
$value = $Cacheer->in("reports")->remember("daily-summary", 3600, function () {
    // Expensive computation; runs once, then cached for an hour.
    return ["orders" => 1287, "revenue" => 42_850.50];
});

print_r($value);
