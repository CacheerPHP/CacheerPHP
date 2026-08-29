<?php

declare(strict_types=1);

namespace Silviooosilva\CacheerPhp\Observability;

use Silviooosilva\CacheerPhp\Contracts\EventDispatcher;

/**
 * A process-global, opt-in observability tap.
 *
 * v6 is instance-first: a cache emits nothing unless you wrap it with
 * {@see \Silviooosilva\CacheerPhp\Cacheer::instrumented()} or inject a dispatcher.
 * This class is the one deliberate exception — a dormant global tap that stays a
 * no-op until something registers a listener. It exists so a telemetry package
 * (e.g. cacheerphp/monitor) can self-register at autoload time and observe every
 * cache built through the named constructors, with zero wiring in user code.
 *
 * It never changes cache behavior: with no listeners the named constructors take
 * the plain, uninstrumented path, so there is no overhead and no observable
 * effect. It only ever fans typed {@see CacheEvent}s out to registered listeners,
 * each guarded so a failing listener can never break a cache operation.
 */
final class Telemetry
{
    /**
     * @var ?EventBus
     */
    private static ?EventBus $bus = null;

    /**
     * @var int
     */
    private static int $listenerCount = 0;

    /**
     * @var bool
     */
    private static bool $captureValues = false;

    /**
     * Register a listener that receives every {@see CacheEvent} emitted by caches
     * built through the named constructors after this call.
     *
     * @param callable(CacheEvent): void $listener
     */
    public static function listen(callable $listener): void
    {
        self::bus()->listen($listener);
        self::$listenerCount++;
    }

    /**
     * Whether any listener is registered. The named constructors consult this to
     * decide whether to take the instrumented path at all.
     *
     * @return bool
     */
    public static function hasListeners(): bool
    {
        return self::$listenerCount > 0;
    }

    /**
     * The shared dispatcher that fans events out to every registered listener.
     *
     * @return EventDispatcher
     */
    public static function dispatcher(): EventDispatcher
    {
        return self::bus();
    }

    /**
     * Opt into carrying cached values on emitted events, so a listener can build
     * value previews. Off by default — values never leave the process unless a
     * listener is explicitly told to capture them.
     *
     * @param bool $capture
     */
    public static function captureValues(bool $capture = true): void
    {
        self::$captureValues = $capture;
    }

    /**
     * @return bool
     */
    public static function capturesValues(): bool
    {
        return self::$captureValues;
    }

    /**
     * Drop all listeners and reset value capture. Primarily for tests and
     * long-running processes that reconfigure telemetry.
     */
    public static function reset(): void
    {
        self::$bus = null;
        self::$listenerCount = 0;
        self::$captureValues = false;
    }

    /**
     * @return EventBus
     */
    private static function bus(): EventBus
    {
        return self::$bus ??= new EventBus();
    }
}
