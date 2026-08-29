<?php

declare(strict_types=1);

namespace Silviooosilva\CacheerPhp\Stores\Support;

use Silviooosilva\CacheerPhp\Contracts\Clock;
use Silviooosilva\CacheerPhp\Contracts\Lock;
use Silviooosilva\CacheerPhp\Contracts\RedisConnection;
use Silviooosilva\CacheerPhp\Kernel\Ttl;

/**
 * A distributed lock backed by SET ... NX with a random owner token.
 *
 * Acquisition is Redis's atomic NX. Release runs a small Lua script that
 * deletes the key only if it still holds this instance's token — the one place
 * Lua is genuinely required, so a lock whose TTL expired and was re-acquired by
 * someone else is never released out from under the new owner.
 */
final class RedisLock implements Lock
{
    private const RETRY_MICROSECONDS = 50_000;

    private const UNLOCK = <<<'LUA'
        if redis.call("get", KEYS[1]) == ARGV[1] then
            return redis.call("del", KEYS[1])
        end
        return 0
        LUA;

    /**
     * @var string
     */
    private readonly string $token;

    /**
     * @var bool
     */
    private bool $held = false;

    /**
     * @param RedisConnection $redis
     * @param Clock $clock
     * @param string $key
     * @param Ttl $ttl
     */
    public function __construct(
        private readonly RedisConnection $redis,
        private readonly Clock $clock,
        private readonly string $key,
        private readonly Ttl $ttl,
    ) {
        $this->token = bin2hex(random_bytes(16));
    }

    /**
     * @return bool
     */
    public function acquire(): bool
    {
        $seconds = $this->ttl->inSeconds();
        $this->held = $this->redis->setIfAbsent($this->key, $this->token, $seconds === null ? null : $seconds * 1000);

        return $this->held;
    }

    /**
     * @param float $seconds
     * @return bool
     */
    public function block(float $seconds): bool
    {
        $deadline = $this->clock->nowFloat() + $seconds;

        while (true) {
            if ($this->acquire()) {
                return true;
            }

            if ($this->clock->nowFloat() >= $deadline) {
                return false;
            }

            $this->clock->sleep(self::RETRY_MICROSECONDS);
        }
    }

    /**
     * @return bool
     */
    public function release(): bool
    {
        if (!$this->held) {
            return false;
        }

        $released = (int) $this->redis->eval(self::UNLOCK, [$this->key], [$this->token]) > 0;
        $this->held = false;

        return $released;
    }
}
