<?php

declare(strict_types=1);

namespace Silviooosilva\CacheerPhp\Exceptions;

use Psr\SimpleCache\InvalidArgumentException as PsrInvalidArgumentException;

/**
 * Class CacheInvalidArgumentException
 *
 * Thrown when a cache key or argument fails PSR-16 validation.
 *
 * Implements \Psr\SimpleCache\InvalidArgumentException so it can be caught
 * by PSR-16 consumers as required by the specification.
 *
 * @author Sílvio Silva <https://github.com/silviooosilva>
 * @package Silviooosilva\CacheerPhp
 */
class CacheInvalidArgumentException extends \InvalidArgumentException implements PsrInvalidArgumentException
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
