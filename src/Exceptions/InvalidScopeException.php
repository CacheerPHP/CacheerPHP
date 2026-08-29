<?php

declare(strict_types=1);

namespace Silviooosilva\CacheerPhp\Exceptions;

/**
 * Thrown for a scope segment that is empty, over 255 bytes, or contains slashes
 * or control characters — anything that could not be encoded into a backend
 * keyspace safely.
 */
final class InvalidScopeException extends \InvalidArgumentException implements CacheException
{
}
