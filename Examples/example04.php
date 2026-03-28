<?php
require_once __DIR__ . "/../vendor/autoload.php";

use Silviooosilva\CacheerPhp\Cacheer;
use Silviooosilva\CacheerPhp\Config\Option\Builder\OptionBuilder;

// Old way to set options (v4 and earlier) — now replaced by OptionBuilder

// $options = [
//     "cacheDir" =>  __DIR__ . "/cache",
// ];

$options = OptionBuilder::forFile()
        ->dir( __DIR__ . "/cache")
        ->build();

$Cacheer = new Cacheer($options);

// Data to be stored in the cache with a namespace
$namespace = 'session_data_01';
$cacheKey = 'session_456';
$sessionData = [
    'user_id' => 456,
    'login_time' => time(),
];

// Storing data in the cache with a namespace
$Cacheer->putCache($cacheKey, $sessionData, $namespace);

// Retrieving data from the cache
$cachedSessionData = $Cacheer->getCache($cacheKey, $namespace);

if ($Cacheer->isSuccess()) {
    echo "Cache Found: ";
    print_r($cachedSessionData);
} else {
    echo $Cacheer->getMessage();
}
