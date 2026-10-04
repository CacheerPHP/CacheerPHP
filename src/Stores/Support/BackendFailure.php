<?php

declare(strict_types=1);

namespace Silviooosilva\CacheerPhp\Stores\Support;

use LogicException;
use Silviooosilva\CacheerPhp\Exceptions\CacheException;
use Throwable;

/**
 * Tells a backend outage apart from errors that failing over would only hide.
 *
 * Programming errors (\Error, \LogicException — which includes invalid
 * arguments) and every CacheException (a corrupt or oversized payload, a
 * counter overflow, a lock timeout) say nothing about whether the backend is
 * reachable, so they must surface rather than trip a breaker or fall back to
 * another store. Anything else — a PDO, Redis, or I/O failure — is an outage.
 *
 * @internal
 */
final class BackendFailure
{
    /**
     * @param Throwable $error
     * @return bool
     */
    public static function isOutage(Throwable $error): bool
    {
        return !$error instanceof \Error
            && !$error instanceof LogicException
            && !$error instanceof CacheException;
    }
}
