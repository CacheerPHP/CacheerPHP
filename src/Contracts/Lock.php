<?php

declare(strict_types=1);

namespace Silviooosilva\CacheerPhp\Contracts;

interface Lock
{
    public function acquire(): bool;

    public function block(float $seconds): bool;

    public function release(): bool;
}
