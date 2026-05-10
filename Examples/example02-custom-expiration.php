<?php
require_once __DIR__ . "/../vendor/autoload.php";

use Silviooosilva\CacheerPhp\Cacheer;
use Silviooosilva\CacheerPhp\Config\Option\Builder\OptionBuilder;

// Old way to set options (v4 and earlier) — now replaced by OptionBuilder

// $options = [
//     "cacheDir" =>  __DIR__ . "/cache",
//     "expirationTime" => "2 hour"
// ];

$options = OptionBuilder::forFile()
        ->dir(__DIR__ . "/cache")
        ->expirationTime()->hour(2)
        ->build();

$Cacheer = new Cacheer($options);

// Data to be stored in the cache
$cacheKey = 'daily_stats';
$dailyStats = [
    'visits' => 1500,
    'signups' => 35,
    'revenue' => 500.75,
];

// Storing data in the cache
$Cacheer->putCache($cacheKey, $dailyStats);

// Retrieving cached data (TTL: 2 hours)
$cachedStats = $Cacheer->getCache($cacheKey);

if ($Cacheer->isSuccess()) {
    echo "Cache Found: ";
    print_r($cachedStats);
} else {
    echo $Cacheer->getMessage();
}
