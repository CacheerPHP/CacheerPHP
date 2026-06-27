<?php

namespace Silviooosilva\CacheerPhp\Events;

use Silviooosilva\CacheerPhp\Contracts\CacheEventListener;

/**
 * Class CacheEventDispatcher
 *
 * @author Sílvio Silva <https://github.com/silviooosilva>
 * @package Silviooosilva\CacheerPhp\Events
 */
final class CacheEventDispatcher
{
    /**
     * @var array<CacheEventListener>
     */
    private static array $listeners = [];

    /**
     * Register a listener that will be notified after every cache operation.
     *
     * @param CacheEventListener $listener
     * @return void
     */
    public static function addListener(CacheEventListener $listener): void
    {
        self::$listeners[] = $listener;
    }

    /**
     * Remove all registered event listeners.
     *
     * @return void
     */
    public static function removeListeners(): void
    {
        self::$listeners = [];
    }

    /**
     * Returns true when at least one listener is registered.
     *
     * @return bool
     */
    public static function hasListeners(): bool
    {
        return self::$listeners !== [];
    }

    /**
     * Dispatch a cache event to all registered listeners.
     *
     * @param string $method
     * @param bool   $success
     * @param array  $parameters
     * @param float  $durationMs
     * @param string $driver
     * @param mixed  $result      Return value of the cache operation (used for value capture)
     * @return void
     */
    public static function dispatch(string $method, bool $success, array $parameters, float $durationMs, string $driver = '', mixed $result = null): void
    {
        if (self::$listeners === [] || self::isConfigLikeMethod($method)) {
            return;
        }

        $event = self::resolveEventName($method, $success);

        $context = [
            'namespace'   => self::extractNamespace($method, $parameters),
            'duration_ms' => round($durationMs, 3),
            'success'     => $success,
            'driver'      => $driver,
        ];

        // Expose value and TTL for write operations so listeners can capture them
        if (self::isWriteMethod($method)) {
            if (isset($parameters[1])) {
                $context['value'] = $parameters[1];
            }
            $context['ttl'] = self::extractTtl($method, $parameters);
        }

        // Expose the retrieved value for successful read operations
        if ($method === 'getCache' && $success) {
            $context['value'] = $result;
        }

        foreach (self::$listeners as $listener) {
            $listener->on($event, self::extractStringParam($parameters, 0), $context);
        }
    }

    /**
     * Safely extract a string parameter from the parameters array.
     *
     * @param array $parameters
     * @param int   $index
     * @return string
     */
    private static function extractStringParam(array $parameters, int $index): string
    {
        return isset($parameters[$index]) && is_string($parameters[$index]) ? $parameters[$index] : '';
    }

    /**
     * Extract the namespace from parameters, accounting for different method signatures.
     *
     * Write methods: (key, value, namespace, ttl)  → namespace at index 2
     * forever:       (key, value)                   → no namespace (returns '')
     * renewCache:    (key, ttl, namespace)           → namespace at index 2
     * Other methods: (key, namespace, ...)           → namespace at index 1
     *
     * @param string $method
     * @param array  $parameters
     * @return string
     */
    private static function extractNamespace(string $method, array $parameters): string
    {
        return match (true) {
            in_array($method, ['putCache', 'add'], true) => self::extractStringParam($parameters, 2),
            $method === 'renewCache'                     => self::extractStringParam($parameters, 2),
            $method === 'forever'                        => '',
            default                                      => self::extractStringParam($parameters, 1),
        };
    }

    /**
     * Extract TTL from parameters for write operations.
     *
     * @param string $method
     * @param array  $parameters
     * @return mixed
     */
    private static function extractTtl(string $method, array $parameters): mixed
    {
        return match ($method) {
            'putCache', 'add' => $parameters[3] ?? null,
            'renewCache'      => $parameters[1] ?? null,
            'forever'         => null,
            default           => null,
        };
    }

    /**
     * Map a method name to its telemetry event type.
     *
     * @param string $method
     * @param bool   $success
     * @return string
     */
    private static function resolveEventName(string $method, bool $success): string
    {
        return match ($method) {
            'getCache'        => $success ? 'hit' : 'miss',
            'putCache'        => 'put',
            'clearCache'      => 'clear',
            'flushCache'      => 'flush',
            'has'             => 'has',
            'putMany'         => 'put_many',
            'getMany'         => 'get_many',
            'getAll'          => 'get_all',
            'appendCache'     => 'append',
            'forever'         => 'put_forever',
            'renewCache'      => 'renew',
            'tag'             => 'tag',
            'flushTag'        => 'flush_tag',
            'remember'        => 'remember',
            'rememberForever' => 'remember_forever',
            'add'             => 'add',
            'increment'       => 'increment',
            'decrement'       => 'decrement',
            'getAndForget'    => 'get_and_forget',
            default           => $method,
        };
    }

    /**
     * Returns true for methods that write a value to the cache.
     *
     * @param string $method
     * @return bool
     */
    private static function isWriteMethod(string $method): bool
    {
        return in_array($method, ['putCache', 'forever', 'add', 'renewCache'], true);
    }

    /**
     * Returns true for configuration/introspection methods that should not emit events.
     *
     * @param string $method
     * @return bool
     */
    private static function isConfigLikeMethod(string $method): bool
    {
        return in_array($method, [
            'getOptions', 'setOption', 'setOptions', 'getOption',
            'getCacheStore', 'setCacheStore', 'stats',
            'syncState', 'setInternalState',
        ], true);
    }
}
