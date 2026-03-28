<?php

namespace Silviooosilva\CacheerPhp;

use Closure;
use DateInterval;
use Silviooosilva\CacheerPhp\Interface\CacheerInterface;
use Silviooosilva\CacheerPhp\Helpers\CacheConfig;
use Silviooosilva\CacheerPhp\Utils\CacheDataFormatter;
use Silviooosilva\CacheerPhp\Utils\CacheDriver;
use RuntimeException;
use Silviooosilva\CacheerPhp\Service\CacheRetriever;
use Silviooosilva\CacheerPhp\Service\CacheMutator;
use BadMethodCallException;

/**
 * Class Cacheer
 *
 * @author Sílvio Silva <https://github.com/silviooosilva>
 * @package Silviooosilva\CacheerPhp
 *
 * @method static bool add(string $cacheKey, mixed $cacheData, string $namespace = '', int|string|\DateInterval|null $ttl = 3600)
 * @method bool add(string $cacheKey, mixed $cacheData, string $namespace = '', int|string|\DateInterval|null $ttl = 3600)
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
 * @method static mixed getOption(string $key, mixed $default = null)
 * @method mixed getOption(string $key, mixed $default = null)
 * @method static array getOptions()
 * @method array getOptions()
 * @method static bool has(string $cacheKey, string $namespace = '')
 * @method bool has(string $cacheKey, string $namespace = '')
 * @method static bool increment(string $cacheKey, int $amount = 1, string $namespace = '')
 * @method bool increment(string $cacheKey, int $amount = 1, string $namespace = '')
 * @method static bool putCache(string $cacheKey, mixed $cacheData, string $namespace = '', int|string|\DateInterval|null $ttl = 3600)
 * @method bool putCache(string $cacheKey, mixed $cacheData, string $namespace = '', int|string|\DateInterval|null $ttl = 3600)
 * @method static bool putMany(array $items, string $namespace = '', int $batchSize = 100)
 * @method bool putMany(array $items, string $namespace = '', int $batchSize = 100)
 * @method static bool tag(string $tag, string ...$keys)
 * @method bool tag(string $tag, string ...$keys)
 * @method static bool flushTag(string $tag)
 * @method bool flushTag(string $tag)
 * @method static mixed remember(string $cacheKey, int|string|\DateInterval|null $ttl, Closure $callback)
 * @method mixed remember(string $cacheKey, int|string|\DateInterval|null $ttl, Closure $callback)
 * @method static mixed rememberForever(string $cacheKey, Closure $callback)
 * @method mixed rememberForever(string $cacheKey, Closure $callback)
 * @method static bool renewCache(string $cacheKey, int|string|\DateInterval|null $ttl = 3600, string $namespace = '')
 * @method bool renewCache(string $cacheKey, int|string|\DateInterval|null $ttl = 3600, string $namespace = '')
 * @method static \Silviooosilva\CacheerPhp\Helpers\CacheConfig setConfig()
 * @method \Silviooosilva\CacheerPhp\Helpers\CacheConfig setConfig()
 * @method static \Silviooosilva\CacheerPhp\Utils\CacheDriver setDriver()
 * @method \Silviooosilva\CacheerPhp\Utils\CacheDriver setDriver()
 * @method static void setUp(array $options)
 * @method void setUp(array $options)
 */
final class Cacheer
{
    private string $message;
    private bool $success;

    /** @var bool Whether the formatter is enabled for output. */
    private bool $formatted = false;

    /** @var bool Whether gzip compression is applied to stored values. */
    private bool $compression = false;

    /** @var string|null AES-256-CBC encryption key, or null if disabled. */
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
     * @throws RuntimeException|\Silviooosilva\CacheerPhp\Exceptions\CacheFileException
     */
    public function __construct(array $options = [], bool $formatted = false)
    {
        $this->formatted = $formatted;
        $this->options   = $options;
        $this->retriever = new CacheRetriever($this);
        $this->mutator   = new CacheMutator($this);
        $this->config    = new CacheConfig($this);
        $this->setDriver()->useDefaultDriver();
    }

    /**
     * Dynamically handle instance-method calls via delegation to service classes.
     *
     * @param string $method
     * @param array  $parameters
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

        throw new BadMethodCallException("Method {$method} does not exist on Cacheer.");
    }

    /**
     * Handle dynamic static calls by routing them through the shared instance.
     *
     * @param string $method
     * @param array  $parameters
     * @return mixed
     */
    public static function __callStatic(string $method, array $parameters): mixed
    {
        return self::instance()->__call($method, $parameters);
    }

    /**
     * Enable AES-256-CBC encryption for cached data.
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
     * Enable or disable gzip compression of cached values.
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
     * Toggle the output formatter.
     *
     * When enabled, read methods return a CacheDataFormatter instance that
     * exposes toJson(), toArray(), toObject(), and toString() helpers.
     *
     * @return void
     */
    public function useFormatter(): void
    {
        $this->formatted = !$this->formatted;
    }

    /**
     * Returns the active cache store implementation.
     *
     * @return CacheerInterface
     */
    public function getCacheStore(): CacheerInterface
    {
        return $this->cacheStore;
    }

    /**
     * Replaces the active cache store implementation.
     *
     * Called by CacheDriver when switching drivers (useFileDriver, useRedisDriver …).
     *
     * @param CacheerInterface $store
     * @return void
     */
    public function setCacheStore(CacheerInterface $store): void
    {
        $this->cacheStore = $store;
    }

    /**
     * Returns the value of a single configuration option, or a default if the key is not set.
     *
     * @param string $key
     * @param mixed  $default
     * @return mixed
     */
    public function getOption(string $key, mixed $default = null): mixed
    {
        return $this->options[$key] ?? $default;
    }

    /**
     * Returns the full options array.
     *
     * @return array
     */
    public function getOptions(): array
    {
        return $this->options;
    }

    /**
     * Sets a single option key/value pair.
     *
     * @param string $key
     * @param mixed  $value
     * @return Cacheer
     */
    public function setOption(string $key, mixed $value): Cacheer
    {
        $this->options[$key] = $value;
        return $this;
    }

    /**
     * Replaces the entire options array.
     *
     * Used by CacheConfig::setUp() to reset all options at once.
     *
     * @param array $options
     * @return void
     */
    public function setOptions(array $options): void
    {
        $this->options = $options;
    }

    /**
     * Returns whether the last operation was successful.
     *
     * @return bool
     */
    public function isSuccess(): bool
    {
        return $this->success;
    }

    /**
     * Returns the human-readable message from the last operation.
     *
     * @return string
     */
    public function getMessage(): string
    {
        return $this->message;
    }

    /**
     * Copies status from the active cache store into this Cacheer instance.
     *
     * @return void
     */
    public function syncState(): void
    {
        $this->setMessage($this->cacheStore->getMessage(), $this->cacheStore->isSuccess());
    }

    /**
     * Directly sets the internal status message and success flag.
     *
     * @param string $message
     * @param bool   $success
     * @return void
     */
    public function setInternalState(string $message, bool $success): void
    {
        $this->setMessage($message, $success);
    }

    public function isFormatted(): bool
    {
        return $this->formatted;
    }

    public function isCompressionEnabled(): bool
    {
        return $this->compression;
    }

    public function getEncryptionKey(): ?string
    {
        return $this->encryptionKey;
    }

    /**
     * Returns basic runtime information about the active cache configuration.
     *
     * Useful for logging, health-check endpoints, or debugging.
     *
     * @return array{driver: string, compression: bool, encryption: bool}
     */
    public function stats(): array
    {
        return [
            'driver'      => get_class($this->cacheStore),
            'compression' => $this->compression,
            'encryption'  => $this->encryptionKey !== null,
        ];
    }

    /**
     * Resets the shared static instance to null.
     *
     * Call this in tearDown() when using the static facade in tests so each
     * test case starts with a clean state.
     *
     * @return void
     */
    public static function resetInstance(): void
    {
        self::$staticInstance = null;
    }

    /**
     * Replaces the shared static instance with a custom one.
     *
     * Allows injecting a pre-configured Cacheer (e.g. with a mock driver or
     * specific options) before static-facade calls in tests.
     *
     * @param self $instance
     * @return void
     */
    public static function setInstance(self $instance): void
    {
        self::$staticInstance = $instance;
    }

    /**
     * Returns (and lazily creates) the shared instance used by static calls.
     *
     * @return self
     */
    private static function instance(): self
    {
        if (self::$staticInstance === null) {
            self::$staticInstance = new self();
        }
        return self::$staticInstance;
    }

    /**
     * Sets the internal message and success flag.
     *
     * @param string $message
     * @param bool   $success
     * @return void
     */
    private function setMessage(string $message, bool $success): void
    {
        $this->message = $message;
        $this->success = $success;
    }
}
