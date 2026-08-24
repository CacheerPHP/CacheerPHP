<?php

declare(strict_types=1);

namespace Silviooosilva\CacheerPhp\Config;

use Closure;
use LogicException;
use PDO;
use Silviooosilva\CacheerPhp\Cacheer;
use Silviooosilva\CacheerPhp\Contracts\Clock;
use Silviooosilva\CacheerPhp\Contracts\Compressor;
use Silviooosilva\CacheerPhp\Contracts\Encrypter;
use Silviooosilva\CacheerPhp\Contracts\RedisConnection;
use Silviooosilva\CacheerPhp\Contracts\Serializer;
use Silviooosilva\CacheerPhp\Kernel\Ttl;
use Silviooosilva\CacheerPhp\Storage\Encryption\Keyring;

/**
 * Fluent assembler for a Cacheer: pick a store, describe the storage pipeline
 * (compression / encryption / limits), optionally add a default policy, then
 * call create().
 *
 * The v6 reimagining of v5's OptionBuilder — but it returns a ready, typed
 * Cacheer, not a stringly options array.
 * Everything here is sugar over the named constructors, {@see PipelineConfig},
 * and {@see CachePolicy}; power users can still reach for those directly.
 *
 *     $cache = Cacheer::build()
 *         ->file('/var/cache')
 *         ->gzip()
 *         ->encryptWithPassphrases(['current' => $secret], 'current')
 *         ->maxValueBytes(2_000_000)
 *         ->defaultTtl('10 minutes')
 *         ->jitter(0.10)
 *         ->create();
 */
final class CacheerBuilder
{
    private string $driver = 'array';

    private ?string $directory = null;

    private ?PDO $pdo = null;

    private string $table = 'cacheer_store';

    private ?RedisConnection $connection = null;

    private string $prefix = 'cacheer';

    private ?Clock $clock = null;

    private PipelineConfig $pipeline;

    private ?CachePolicy $policy = null;

    public function __construct()
    {
        $this->pipeline = PipelineConfig::default();
    }

    // ---------------------------------------------------------------- store --

    public function inMemory(): self
    {
        $this->driver = 'array';

        return $this;
    }

    public function file(string $directory): self
    {
        $this->driver = 'file';
        $this->directory = $directory;

        return $this;
    }

    public function database(PDO $pdo, string $table = 'cacheer_store'): self
    {
        $this->driver = 'database';
        $this->pdo = $pdo;
        $this->table = $table;

        return $this;
    }

    public function redis(RedisConnection $connection, string $prefix = 'cacheer'): self
    {
        $this->driver = 'redis';
        $this->connection = $connection;
        $this->prefix = $prefix;

        return $this;
    }

    // ------------------------------------------------------------- pipeline --

    public function serializer(Serializer $serializer): self
    {
        $this->pipeline = $this->pipeline->withSerializer($serializer);

        return $this;
    }

    public function json(): self
    {
        $this->pipeline = $this->pipeline->withJsonSerializer();

        return $this;
    }

    public function compressor(Compressor $compressor): self
    {
        $this->pipeline = $this->pipeline->withCompressor($compressor);

        return $this;
    }

    public function gzip(int $level = 6): self
    {
        $this->pipeline = $this->pipeline->withGzip($level);

        return $this;
    }

    public function encrypter(Encrypter $encrypter): self
    {
        $this->pipeline = $this->pipeline->withEncrypter($encrypter);

        return $this;
    }

    public function encrypt(Keyring $keyring): self
    {
        $this->pipeline = $this->pipeline->withKeyring($keyring);

        return $this;
    }

    /**
     * @param array<string, string> $passphrases key id => passphrase
     */
    public function encryptWithPassphrases(array $passphrases, string $activeId): self
    {
        return $this->encrypt(Keyring::fromPassphrases($passphrases, $activeId));
    }

    public function maxValueBytes(int $bytes): self
    {
        $this->pipeline = $this->pipeline->withMaxValueBytes($bytes);

        return $this;
    }

    // --------------------------------------------------------------- policy --

    public function defaultTtl(Ttl|int|string $ttl): self
    {
        $this->policy = $this->policy()->withTtl($ttl);

        return $this;
    }

    public function jitter(float $fraction, ?Closure $randomizer = null): self
    {
        $this->policy = $this->policy()->withJitter($fraction, $randomizer);

        return $this;
    }

    public function negativeTtl(Ttl|int|string $ttl): self
    {
        $this->policy = $this->policy()->withNegativeTtl($ttl);

        return $this;
    }

    public function serveStaleOnError(Ttl|int|string $grace): self
    {
        $this->policy = $this->policy()->withServeStaleOnError($grace);

        return $this;
    }

    public function clock(Clock $clock): self
    {
        $this->clock = $clock;

        return $this;
    }

    /**
     * Build the cache.
     */
    public function create(): Cacheer
    {
        $cache = match ($this->driver) {
            'file'     => Cacheer::file($this->require($this->directory, 'file(directory)'), $this->pipeline, $this->clock),
            'database' => Cacheer::database($this->require($this->pdo, 'database(pdo)'), $this->table, $this->pipeline, $this->clock),
            'redis'    => Cacheer::redis($this->require($this->connection, 'redis(connection)'), $this->prefix, $this->pipeline, $this->clock),
            default    => Cacheer::inMemory($this->clock),
        };

        return $this->policy !== null ? $cache->withPolicy($this->policy) : $cache;
    }

    private function policy(): CachePolicy
    {
        return $this->policy ??= CachePolicy::defaults();
    }

    /**
     * @template T
     * @param T|null $value
     * @return T
     */
    private function require(mixed $value, string $call): mixed
    {
        return $value ?? throw new LogicException("Call {$call} before create().");
    }
}
