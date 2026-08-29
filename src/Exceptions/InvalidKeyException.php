<?php

declare(strict_types=1);

namespace Silviooosilva\CacheerPhp\Exceptions;

/**
 * Thrown for a key that is empty, over 1024 bytes, or carries control characters.
 */
final class InvalidKeyException extends \InvalidArgumentException implements CacheException
{
}
