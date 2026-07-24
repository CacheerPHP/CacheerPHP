<?php

declare(strict_types=1);

namespace Silviooosilva\CacheerPhp\Contracts;

use Silviooosilva\CacheerPhp\Kernel\Ttl;

interface LockingStore
{
    public function lock(string $name, Ttl $ttl): Lock;
}
