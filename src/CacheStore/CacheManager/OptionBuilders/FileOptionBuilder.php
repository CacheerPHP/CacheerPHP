<?php

declare(strict_types=1);

namespace Silviooosilva\CacheerPhp\CacheStore\CacheManager\OptionBuilders;

use Silviooosilva\CacheerPhp\Support\TimeBuilder;

/**
 * Class FileOptionBuilder
 *
 * @internal This class should not be used directly. Use OptionBuilder::forFile() instead.
 *
 * @author Sílvio Silva <https://github.com/silviooosilva>
 * @package Silviooosilva\CacheerPhp
 */
final class FileOptionBuilder
{
  private function __construct() {}

  public static function create(): self
  {
    return new self();
  }

  private ?string $cacheDir = null;
  private ?string $loggerPath = null;
  private ?string $expirationTime = null;
  private ?string $flushAfter = null;
  private array $options = [];

  /**
  * Directory where cache files will be stored.
  *
  * @param string $cacheDir
  * @return $this
  */
  public function dir(string $cacheDir)
  {
    $this->cacheDir = $cacheDir;
    return $this;
  }

  /**
  * Logger path for cache operations.
  *
  * @param string $loggerPath
  * @return $this
  */
  public function loggerPath(string $loggerPath)
  {
    $this->loggerPath = $loggerPath;
    return $this;
  }

  /**
  * Sets the expiration time for cache items.
  * @param ?string $expirationTime
  * @return $this|TimeBuilder
  */
  public function expirationTime(?string $expirationTime = null)
  {

    if (!is_null($expirationTime)) {
      $this->expirationTime = $expirationTime;
      return $this;
    }

    return new TimeBuilder(function ($formattedTime){
      $this->expirationTime = $formattedTime;
    }, $this);
  }

  /**
  * Sets the flush time for cache items.
  * This is the time after which the cache will be flushed.
  *
  * @param ?string $flushAfter
  * @return $this|TimeBuilder
  */
  public function flushAfter(?string $flushAfter = null)
  {

    if (!is_null($flushAfter)) {
      $this->flushAfter = mb_strtolower($flushAfter, 'UTF-8');
      return $this;
    }

    return new TimeBuilder(function ($formattedTime){
      $this->flushAfter = $formattedTime;
    }, $this);
  }

  /**
  * Builds the options array for file cache configuration.
  * @return array
  */
  public function build()
  {
    return $this->validated();
  }

  private function validated()
  {
    foreach ($this->properties() as $key => $value) {
        if ($this->isValidAndNotNull($value)) {
            $this->options[$key] = $value;
        }
    }
    return $this->options;
  }

  private function isValidAndNotNull(mixed $data)
  {
    return !empty($data) ? true : false;
  }

  private function properties()
  {
    $properties = [
      'cacheDir' => $this->cacheDir,
      'loggerPath' => $this->loggerPath,
      'expirationTime' => $this->expirationTime,
      'flushAfter' => $this->flushAfter
    ];

    return $properties;
  }
}
