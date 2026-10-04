<?php

declare(strict_types=1);

namespace Silviooosilva\CacheerPhp\Stores\Support;

use OverflowException;
use Silviooosilva\CacheerPhp\Exceptions\StoreOperationFailedException;
use Silviooosilva\CacheerPhp\Kernel\Key;

/**
 * Checked integer arithmetic for counters.
 *
 * PHP silently turns an overflowing int into a float, which a store would then
 * persist as a non-integer and break every later increment. This fails instead,
 * before anything is written, so the previous value stays intact.
 *
 * @internal
 */
final class CounterArithmetic
{
    /**
     * @param Key $key
     * @param int $current
     * @param int $amount
     * @return int
     */
    public static function add(Key $key, int $current, int $amount): int
    {
        $overflows = $amount > 0
            ? $current > PHP_INT_MAX - $amount
            : $current < PHP_INT_MIN - $amount;

        if ($overflows) {
            throw new StoreOperationFailedException(
                'increment',
                $key,
                new OverflowException('The counter would exceed the platform integer range.'),
            );
        }

        return $current + $amount;
    }
}
