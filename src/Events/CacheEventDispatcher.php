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
     * @return void
     */
    public static function dispatch(string $method, bool $success, array $parameters, float $durationMs, string $driver = ''): void
    {
        if (self::$listeners === [] || self::isConfigLikeMethod($method)) {
            return;
        }

        $event = self::resolveEventName($method, $success);
        $key = isset($parameters[0]) && is_string($parameters[0]) ? $parameters[0] : '';
        $ns = isset($parameters[1]) && is_string($parameters[1]) ? $parameters[1] : '';

        $context = [
            'namespace'   => $ns,
            'duration_ms' => round($durationMs, 3),
            'success'     => $success,
            'driver'      => $driver,
        ];

        foreach (self::$listeners as $listener) {
            $listener->on($event, $key, $context);
        }
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
