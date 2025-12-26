<?php

namespace Silviooosilva\CacheerPhp;

use Closure;
use Silviooosilva\CacheerPhp\CacheStore\DatabaseCacheStore;
use Silviooosilva\CacheerPhp\CacheStore\FileCacheStore;
use Silviooosilva\CacheerPhp\CacheStore\RedisCacheStore;
use Silviooosilva\CacheerPhp\CacheStore\ArrayCacheStore;
use Silviooosilva\CacheerPhp\Interface\CacheerInterface;
use Silviooosilva\CacheerPhp\Helpers\CacheConfig;
use Silviooosilva\CacheerPhp\Utils\CacheDataFormatter;
use Silviooosilva\CacheerPhp\Utils\CacheDriver;
use RuntimeException;
use Silviooosilva\CacheerPhp\Service\CacheRetriever;
use Silviooosilva\CacheerPhp\Service\CacheMutator;
use BadMethodCallException;

/**
 * Class CacheerPHP
 *
 * @author Sílvio Silva <https://github.com/silviooosilva>
 * @package Silviooosilva\CacheerPhp
 *
 * @method static bool add(string $cacheKey, mixed $cacheData, string $namespace = '', int|string $ttl = 3600)
 * @method bool add(string $cacheKey, mixed $cacheData, string $namespace = '', int|string $ttl = 3600)
 * @method static bool appendCache(string $cacheKey, mixed $cacheData, string $namespace = '')
 * @method bool appendCache(string $cacheKey, mixed $cacheData, string $namespace = '')
 * @method static bool clearCache(string $cacheKey, string $namespace = '')
 * @method bool clearCache(string $cacheKey, string $namespace = '')
 * @method static bool decrement(string $cacheKey, int $amount = 1, string $namespace = '')
 * @method bool decrement(string $cacheKey, int $amount = 1, string $namespace = '')
 * @method static bool flushCache()
 * @method bool flushCache()
 * @method static bool forever(string $cacheKey, mixed $cacheData)
 * @method bool forever(string $cacheKey, mixed $cacheData)
 * @method static mixed getAndForget(string $cacheKey, string $namespace = '')
 * @method mixed getAndForget(string $cacheKey, string $namespace = '')
 * @method static CacheDataFormatter|mixed getAll(string $namespace = '')
 * @method CacheDataFormatter|mixed getAll(string $namespace = '')
 * @method static mixed getCache(string $cacheKey, string $namespace = '', int|string $ttl = 3600)
 * @method mixed getCache(string $cacheKey, string $namespace = '', int|string $ttl = 3600)
 * @method static array|CacheDataFormatter getMany(array $cacheKeys, string $namespace = '', int|string $ttl = 3600)
 * @method array|CacheDataFormatter getMany(array $cacheKeys, string $namespace = '', int|string $ttl = 3600)
 * @method static getOptions(): array
 * @method getOptions(): array
 * @method static bool has(string $cacheKey, string $namespace = '')
 * @method bool has(string $cacheKey, string $namespace = '')
 * @method static bool increment(string $cacheKey, int $amount = 1, string $namespace = '')
 * @method bool increment(string $cacheKey, int $amount = 1, string $namespace = '')
 * @method static bool putCache(string $cacheKey, mixed $cacheData, string $namespace = '', int|string $ttl = 3600)
 * @method bool putCache(string $cacheKey, mixed $cacheData, string $namespace = '', int|string $ttl = 3600)
 * @method static bool putMany(array $items, string $namespace = '', int $batchSize = 100)
 * @method bool putMany(array $items, string $namespace = '', int $batchSize = 100)
 * @method static bool tag(string $tag, string ...$keys)
 * @method bool tag(string $tag, string ...$keys)
 * @method static bool flushTag(string $tag)
 * @method bool flushTag(string $tag)
 * @method static mixed remember(string $cacheKey, int|string $ttl, Closure $callback)
 * @method mixed remember(string $cacheKey, int|string $ttl, Closure $callback)
 * @method static mixed rememberForever(string $cacheKey, Closure $callback)
 * @method mixed rememberForever(string $cacheKey, Closure $callback)
 * @method static bool renewCache(string $cacheKey, int|string $ttl = 3600, string $namespace = '')
 * @method bool renewCache(string $cacheKey, int|string $ttl = 3600, string $namespace = '')
 * @method static setConfig(): CacheConfig
 * @method setConfig(): CacheConfig
 * @method static setDriver(): CacheDriver
 * @method setDriver(): CacheDriver
 * @method static setUp(array $options): void
 * @method setUp(array $options): void
 */
final class Cacheer
{
    /**
    * @var string
    */
    private string $message;

    /**
    * @var boolean
    */
    private bool $success;

    /**
    * @var boolean
    */
    private bool $formatted = false;

    /**
    * @var bool
    */
    private bool $compression = false;

    /**
    * @var string|null
    */
    private ?string $encryptionKey = null;

    /**
    * @var CacheerInterface
    */
    public CacheerInterface $cacheStore;

    /**
    * @var array
    */
    public array $options = [];

    /**
    * @var CacheRetriever
    */
    private CacheRetriever $retriever;

    /**
    * @var CacheMutator
    */
    private CacheMutator $mutator;

    /**
    * @var CacheConfig
    */
    private CacheConfig $config;

    /**
    * @var Cacheer|null
    */
    private static ?Cacheer $staticInstance = null;

/**
    * Cacheer constructor.
    *
    * @param array $options
    * @param bool  $formatted
    * @throws RuntimeException|Exceptions\CacheFileException
 */
    public function __construct(array $options = [], bool $formatted = false)
    {
        $this->formatted = $formatted;
        $this->validateOptions($options);
        $this->retriever = new CacheRetriever($this);
        $this->mutator = new CacheMutator($this);
        $this->config = new CacheConfig($this);
        $this->setDriver()->useDefaultDriver();
    }

    /**
     * Dynamically handle calls to missing instance methods.
     *
     * @param string $method
     * @param array $parameters
     * @return mixed
     * @throws BadMethodCallException
     */
    public function __call(string $method, array $parameters): mixed
    {
        if ($method === 'setConfig') {
            return new CacheConfig($this);
        }

        if ($method === 'setDriver') {
            return new CacheDriver($this);
        }

        $delegates = [$this->mutator, $this->retriever, $this->config];

        foreach ($delegates as $delegate) {
            if (method_exists($delegate, $method)) {
                return $delegate->{$method}(...$parameters);
            }
        }

        throw new BadMethodCallException("Method {$method} does not exist");
    }

    /**
     * Handle dynamic static calls by routing them through an instance.
     *
     * @param string $method
     * @param array $parameters
     * @return mixed
     */
    public static function __callStatic(string $method, array $parameters): mixed
    {
        $instance = self::instance();

        if ($instance === null) {
            throw new \RuntimeException("Cacheer static instance is not initialized.");
        }

        return $instance->__call($method, $parameters);
    }

    /**
    * Enable encryption for cached data
    *
    * @param string $key
    * @return $this
    */
    public function useEncryption(string $key): Cacheer
    {
        $this->encryptionKey = $key;
        return $this;
    }

    /**
    * Enable or disable data compression
    *
    * @param bool $status
    * @return $this
    */
    public function useCompression(bool $status = true): Cacheer
    {
        $this->compression = $status;
        return $this;
    }

    /**
    * Enables or disables the formatter for cache data.
    * 
    * @return void
    */
    public function useFormatter(): void
    {
        $this->formatted = !$this->formatted;
    }

    /**
    * Validates the options provided for the Cacheer instance.
    * 
    * @param array $options
    * @return void
    */
    private function validateOptions(array $options): void
    {
        $this->options = $options;
    }

    /**
    * Checks if the last operation was successful.
    * 
    * @return bool
    */
    public function isSuccess(): bool
    {
        return $this->success;
    }

    /**
    * Sets a message for the cache operation.
    *
    * @param string  $message
    * @param boolean $success
    * @return void
    */
    private function setMessage(string $message, bool $success): void
    {
        $this->message = $message;
        $this->success = $success;
    }

    /**
    * Retrieves the message from the last operation.
    * 
    * @return string
    */
    public function getMessage(): string
    {
        return $this->message;
    }

    /**
     * @return void
     */
    public function syncState(): void
    {
        $this->setMessage($this->cacheStore->getMessage(), $this->cacheStore->isSuccess());
    }

    /**
     * @param string $message
     * @param bool $success
     * @return void
     */
    public function setInternalState(string $message, bool $success): void
    {
        $this->setMessage($message, $success);
    }

    /**
     * @return bool
     */
    public function isFormatted(): bool
    {
        return $this->formatted;
    }

    /**
     * @return bool
     */
    public function isCompressionEnabled(): bool
    {
        return $this->compression;
    }

    /**
     * @return string|null
     */
    public function getEncryptionKey(): ?string
    {
        return $this->encryptionKey;
    }

    /**
     * Get or create the shared Cacheer instance for static calls.
     *
     * @return Cacheer
     */
    private static function instance(): Cacheer
    {
        if (self::$staticInstance === null) {
            self::$staticInstance = new self();
        }
        return self::$staticInstance;
    }
}
