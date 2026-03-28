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

// Cache key to be cleared
$cacheKey = 'user_profile_123';

// Clearing a specific cache item

$Cacheer->clearCache($cacheKey);

if ($Cacheer->isSuccess()) {
    echo $Cacheer->getMessage();
} else {
    echo $Cacheer->getMessage();
}

$Cacheer->flushCache();

if ($Cacheer->isSuccess()) {
    echo $Cacheer->getMessage();
} else {
    echo $Cacheer->getMessage();
}
