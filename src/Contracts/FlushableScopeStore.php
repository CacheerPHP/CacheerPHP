<?php

declare(strict_types=1);

namespace Silviooosilva\CacheerPhp\Contracts;

use Silviooosilva\CacheerPhp\Kernel\Scope;

interface FlushableScopeStore
{
    /**
     * Clear a scope and every nested child scope.
     */
    public function clearScope(Scope $scope): void;
}
