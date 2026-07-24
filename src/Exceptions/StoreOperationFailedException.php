<?php

declare(strict_types=1);

namespace Silviooosilva\CacheerPhp\Exceptions;

use Silviooosilva\CacheerPhp\Kernel\Key;
use Throwable;

final class StoreOperationFailedException extends \RuntimeException implements CacheException
{
    public function __construct(
        public readonly string $operation,
        public readonly ?Key $key,
        Throwable $previous,
    ) {
        $target = $key === null ? '' : sprintf(' for key "%s"', $key->value());

        parent::__construct(
            sprintf('Cache store operation "%s" failed%s.', $operation, $target),
            0,
            $previous,
        );
    }
}
