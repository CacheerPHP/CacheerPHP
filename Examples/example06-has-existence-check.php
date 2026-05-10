<?php

use Silviooosilva\CacheerPhp\Cacheer;

require_once __DIR__ . "/../vendor/autoload.php";

$Cacheer = new Cacheer();
$Cacheer->setDriver()->useRedisDriver();

// Data to be stored in the cache
$cacheKey = 'user_profile_1234';
$userProfile = [
    'id' => 1,
    'name' => 'Silvio Silva',
    'email' => 'gasparsilvio7@gmail.com',
    'role' => 'Developer'
];
$cacheNamespace = 'userData';

// Storing data in the cache
//$Cacheer->putCache($cacheKey, $userProfile, $cacheNamespace);

$Cacheer->has($cacheKey, $cacheNamespace);

// Checking if the cache exists and retrieving the data
if ($Cacheer->isSuccess()) {
    $cachedProfile = $Cacheer->getCache($cacheKey, $cacheNamespace);
    echo "User Profile Found:\n";
    print_r($cachedProfile);
} else {
    echo "Cache not found: " . $Cacheer->getMessage();
}

