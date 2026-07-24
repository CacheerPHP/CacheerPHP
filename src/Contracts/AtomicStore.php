<?php

declare(strict_types=1);

namespace Silviooosilva\CacheerPhp\Contracts;

use Silviooosilva\CacheerPhp\Kernel\Key;
use Silviooosilva\CacheerPhp\Kernel\Ttl;

interface AtomicStore
{
    public function increment(Key $key, int $amount = 1, ?int $initial = null, ?Ttl $ttl = null): int;

    public function compareAndSwap(Key $key, mixed $expected, mixed $value, ?Ttl $ttl = null): bool;
}
