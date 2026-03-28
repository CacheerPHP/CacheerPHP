<?php

declare(strict_types=1);

namespace Silviooosilva\CacheerPhp\CacheStore\CacheManager\OptionBuilders;

use Silviooosilva\CacheerPhp\Support\TimeBuilder;

/**
 * Class RedisOptionBuilder
 *
 * @internal This class should not be used directly. Use OptionBuilder::forRedis() instead.
 *
 * @author Sílvio Silva <https://github.com/silviooosilva>
 * @package Silviooosilva\CacheerPhp
 */
final class RedisOptionBuilder
{
  private function __construct() {}

  public static function create(): self
  {
    return new self();
  }

  private ?string $namespace = null;
  private ?string $loggerPath = null;
  private ?string $expirationTime = null;
  private ?string $flushAfter = null;
  private array $options = [];

  /**
   * Sets the Redis key namespace prefix.
   *
   * @param string $namespace
   * @return $this
   */
  public function setNamespace(string $namespace): self
  {
    $this->namespace = $namespace;
    return $this;
  }

    /**
    * Logger path for cache operations.
    *
    * @param string $loggerPath
    * @return $this
    */  
  public function loggerPath(string $loggerPath): self
  {
    $this->loggerPath = $loggerPath;
    return $this;
  }

  /**
   * Sets the default expiration time for keys.
   *
   * @param ?string $expirationTime
   * @return $this|TimeBuilder
   */
  public function expirationTime(?string $expirationTime = null)
  {
    if (!is_null($expirationTime)) {
      $this->expirationTime = $expirationTime;
      return $this;
    }

    return new TimeBuilder(function ($formattedTime) {
      $this->expirationTime = $formattedTime;
    }, $this);
  }

  /**
   * Sets the auto-flush interval.
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

    return new TimeBuilder(function ($formattedTime) {
      $this->flushAfter = $formattedTime;
    }, $this);
  }

  /**
   * Builds the options array.
   *
   * @return array
   */
  public function build(): array
  {
    return $this->validated();
  }

  private function validated(): array
  {
    foreach ($this->properties() as $key => $value) {
      if (!empty($value)) {
        $this->options[$key] = $value;
      }
    }
    return $this->options;
  }

  private function properties(): array
  {
    return [
      'namespace'      => $this->namespace,
      'loggerPath'     => $this->loggerPath,
      'expirationTime' => $this->expirationTime,
      'flushAfter'     => $this->flushAfter,
    ];
  }
}

