<?php

declare(strict_types=1);

namespace Silviooosilva\CacheerPhp\Contracts;

use Silviooosilva\CacheerPhp\Kernel\Key;

interface TaggableStore
{
    public function tag(Key $key, string ...$tags): void;

    public function clearTag(string $tag): int;
}
