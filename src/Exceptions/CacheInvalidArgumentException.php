<?php

declare(strict_types=1);

namespace Silviooosilva\CacheerPhp\Exceptions;

use Psr\Cache\InvalidArgumentException as Psr6InvalidArgumentException;
use Psr\SimpleCache\InvalidArgumentException as Psr16InvalidArgumentException;

/**
 * Class CacheInvalidArgumentException
 *
 * Thrown when a cache key or argument fails PSR key validation.
 *
 * Implements both \Psr\SimpleCache\InvalidArgumentException (PSR-16) and
 * \Psr\Cache\InvalidArgumentException (PSR-6) so it can be caught by consumers
 * of either specification as required.
 *
 * @author Sílvio Silva <https://github.com/silviooosilva>
 * @package Silviooosilva\CacheerPhp
 * @final
 */
class CacheInvalidArgumentException extends \InvalidArgumentException implements
    Psr16InvalidArgumentException,
    Psr6InvalidArgumentException
{
    /**
     * Creates a new instance with a formatted message.
     *
     * @param string $message
     * @return static
     */
    public static function create(string $message): static
    {
        return new static($message);
    }
}
