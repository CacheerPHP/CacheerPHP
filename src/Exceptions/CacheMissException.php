<?php

declare(strict_types=1);

namespace Silviooosilva\CacheerPhp\Exceptions;

/**
 * Thrown when {@see CacheEntry::value()} is called on a miss.
 *
 * A logic error by definition: check isHit(), or use valueOr().
 */
final class CacheMissException extends \LogicException implements CacheException
{
}
