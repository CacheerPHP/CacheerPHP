<?php

declare(strict_types=1);

namespace Silviooosilva\CacheerPhp\Contracts;

use Silviooosilva\CacheerPhp\Kernel\Key;

/**
 * Grouping keys under tags for bulk invalidation.
 *
 * Tag indexes are best-effort metadata: a key that expires before its tag is
 * flushed is simply a no-op.
 */
interface TaggableStore
{
    /**
     * @param Key $key
     * @param string ...$tags
     */
    public function tag(Key $key, string ...$tags): void;

    /**
     * @param string $tag
     * @return int
     */
    public function clearTag(string $tag): int;
}
