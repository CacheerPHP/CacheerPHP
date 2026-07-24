<?php

declare(strict_types=1);

namespace Silviooosilva\CacheerPhp\Exceptions;

final class InvalidTtlException extends \InvalidArgumentException implements CacheException
{
}
