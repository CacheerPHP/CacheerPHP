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

// API URL and cache key
$apiUrl = 'https://jsonplaceholder.typicode.com/posts';
$cacheKey = 'api_response_' . md5($apiUrl);

// Checking if the API response is already cached
$cachedResponse = $Cacheer->getCache($cacheKey);

if ($Cacheer->isSuccess()) {
    // Use the cached response
    $response = $cachedResponse;
} else {
    // Call the API and store the response in the cache
    $response = file_get_contents($apiUrl);
    $Cacheer->putCache($cacheKey, $response);
}

// Using the API response (from cache or from the call)
$data = json_decode($response, true);
print_r($data);
