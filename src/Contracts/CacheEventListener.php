<?php

namespace Silviooosilva\CacheerPhp\Contracts;

/**
 * Interface CacheEventListener
 *
 * @author Sílvio Silva <https://github.com/silviooosilva>
 * @package Silviooosilva\CacheerPhp\Contracts
 */
interface CacheEventListener
{
    /**
     * Called after a cache operation completes.
     *
     * @param string               $event   Event type: hit, miss, put, clear, flush, put_many,
     *                                       get_many, append, put_forever, renew, tag, flush_tag,
     *                                       remember, remember_forever, add, increment, decrement,
     *                                       get_and_forget, has
     * @param string               $key     Cache key involved, or '' for key-less operations (e.g. flush)
     * @param array<string, mixed> $context Additional context:
     *                                       - namespace   (string)
     *                                       - duration_ms (float)  operation wall-clock time in ms
     *                                       - success     (bool)   whether the operation succeeded
     */
    public function on(string $event, string $key, array $context = []): void;
}
