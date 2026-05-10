<?php

use Silviooosilva\CacheerPhp\Cacheer;

require_once __DIR__ . "/../vendor/autoload.php";

$Cacheer = new Cacheer();
$Cacheer->setDriver()->useRedisDriver();

// Data to be stored in the cache
$cacheKey = 'user_profile_01';
$userProfile = [
    'id' => 1,
    'name' => 'Sílvio Silva',
    'email' => 'gasparsilvio7@gmail.com',
];

// Storing data in the cache
$Cacheer->putCache($cacheKey, $userProfile, ttl: 300);

// Recovering the cache data
if($Cacheer->isSuccess()){
    echo "Cache Found: ";
    print_r($Cacheer->getCache($cacheKey));
} else {
  echo $Cacheer->getMessage();
}

// Renewing the cache data
$Cacheer->renewCache($cacheKey, 3600);

if($Cacheer->isSuccess()){
  echo $Cacheer->getMessage() . PHP_EOL;
} else {
  echo $Cacheer->getMessage() . PHP_EOL;

}