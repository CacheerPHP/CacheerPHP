<?php

declare(strict_types=1);

namespace Silviooosilva\CacheerPhp\Observability;

use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

/**
 * Logs cache events through a PSR-3 logger.
 *
 * Only safe metadata is logged — type, key, store, duration, size — never a
 * cached value, even when value capture is enabled on the emitter. Failures are
 * logged at warning; everything else at debug.
 */
final class PsrLoggerSubscriber
{
    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    public function record(CacheEvent $event): void
    {
        $context = array_filter([
            'key'         => $event->key,
            'store'       => $event->store,
            'duration_us' => $event->durationMicros > 0.0 ? round($event->durationMicros, 2) : null,
            'bytes'       => $event->bytes,
            'count'       => $event->count,
            'error'       => $event->error?->getMessage(),
        ], static fn ($value): bool => $value !== null);

        $level = $event->type === CacheEventType::Failure ? LogLevel::WARNING : LogLevel::DEBUG;

        $this->logger->log($level, $event->type->value, $context);
    }
}
