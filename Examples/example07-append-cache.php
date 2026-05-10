<?php

use Silviooosilva\CacheerPhp\Cacheer;

require_once __DIR__ . "/../vendor/autoload.php";

$Cacheer = new Cacheer();
$Cacheer->setDriver()->useRedisDriver();

// Data to be stored in the cache
$cacheKey = 'user_profile_1';
$userProfile = [
    'id' => 1,
    'name' => 'Sílvio Silva',
    'email' => 'gasparsilvio7@gmail.com',
];

$userProfile02 = [
    'house_number' => 2130,
    'phone' => "(999)999-9999"
];


// Storing data in the cache
$Cacheer->putCache($cacheKey, $userProfile);

// Retrieving data from the cache
if($Cacheer->isSuccess()){
    echo "Cache Found: ";
    print_r($Cacheer->getCache($cacheKey));
} else {
  echo $Cacheer->getMessage();
}


// Merging data into the cache
$Cacheer->appendCache($cacheKey, $userProfile02);

if($Cacheer->isSuccess()){
    echo $Cacheer->getMessage() . PHP_EOL;
    print_r($Cacheer->getCache($cacheKey));
} else {
  echo $Cacheer->getMessage();
}

