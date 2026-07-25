<?php

declare(strict_types=1);

namespace Silviooosilva\CacheerPhp\Contracts;

use Silviooosilva\CacheerPhp\Kernel\Key;

/**
 * Encodes a Key into a deterministic, backend-safe string.
 *
 * A Key's identity is collision-free but may contain bytes a filesystem, Redis
 * keyspace, or SQL column cannot store verbatim. Persistent stores encode keys
 * through this contract before touching their backend.
 */
interface KeyEncoder
{
    public function encode(Key $key): string;
}
