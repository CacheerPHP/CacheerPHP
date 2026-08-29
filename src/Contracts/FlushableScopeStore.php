<?php

declare(strict_types=1);

namespace Silviooosilva\CacheerPhp\Contracts;

use Silviooosilva\CacheerPhp\Kernel\Scope;

/**
 * Clearing a single scope's keyspace, leaving the rest of the store intact.
 *
 * Without it a scoped clear() fails rather than clearing too much.
 */
interface FlushableScopeStore
{
    /**
     * Clear a scope and every nested child scope.
     *
     * @param Scope $scope
     */
    public function clearScope(Scope $scope): void;
}
