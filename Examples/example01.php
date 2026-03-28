<?php
require_once __DIR__ . "/../vendor/autoload.php";

use Silviooosilva\CacheerPhp\Cacheer;
use Silviooosilva\CacheerPhp\CacheStore\CacheManager\OptionBuilders\FileOptionBuilder;
use Silviooosilva\CacheerPhp\Config\Option\Builder\OptionBuilder;

// Old way to set options (v4 and earlier) — now replaced by OptionBuilder

// $options = [
//     "cacheDir" =>  __DIR__ . "/cache",
// ];

$options = OptionBuilder::forFile()
            ->dir(__DIR__ . "/cache")
            ->build();

$Cacheer = new Cacheer($options);

// Data to be stored in the cache
$cacheKey = 'user_profile_1234';
$userProfile = [
    'id' => 123,
    'name' => 'John Doe',
    'email' => 'john.doe@example.com',
];

// Storing data in the cache
$Cacheer->putCache($cacheKey, $userProfile);

// Retrieving data from the cache
$cachedProfile = $Cacheer->getCache($cacheKey);

if ($Cacheer->isSuccess()) {
    echo "Cache Found: ";
    print_r($cachedProfile);
} else {
    echo $Cacheer->getMessage();
}


