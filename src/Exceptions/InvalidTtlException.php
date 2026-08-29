<?php

declare(strict_types=1);

namespace Silviooosilva\CacheerPhp\Exceptions;

/**
 * Thrown for a TTL that is not positive, not expressible, or would overflow the
 * platform's largest expiry.
 */
final class InvalidTtlException extends \InvalidArgumentException implements CacheException
{
}
