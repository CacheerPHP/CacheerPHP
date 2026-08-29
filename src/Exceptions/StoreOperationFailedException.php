<?php

declare(strict_types=1);

namespace Silviooosilva\CacheerPhp\Exceptions;

use Silviooosilva\CacheerPhp\Kernel\Key;
use Throwable;

/**
 * Wraps a backend failure with the operation and key that caused it.
 *
 * The kernel wraps any non-CacheException throwable from a store in this, so
 * callers see one exception type regardless of backend.
 */
final class StoreOperationFailedException extends \RuntimeException implements CacheException
{
    /**
     * @param string $operation
     * @param ?Key $key
     * @param Throwable $previous
     */
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
