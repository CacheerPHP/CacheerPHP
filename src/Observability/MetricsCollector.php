<?php

declare(strict_types=1);

namespace Silviooosilva\CacheerPhp\Observability;

/**
 * In-process metrics aggregated from cache events.
 *
 * Register it as a listener on an EventBus. It tracks operation counts, hit
 * rate, latency (average and max), bytes written, and the higher-level signals
 * (promotions, stale serves, refreshes, lock contention). It never records
 * cache values.
 */
final class MetricsCollector
{
    /**
     * @var array<string, int>
     */
    private array $counts = [];

    /**
     * @var int
     */
    private int $bytesWritten = 0;

    /**
     * @var float
     */
    private float $durationSum = 0.0;

    /**
     * @var int
     */
    private int $durationSamples = 0;

    /**
     * @var float
     */
    private float $durationMax = 0.0;

    /**
     * @param CacheEvent $event
     */
    public function record(CacheEvent $event): void
    {
        $this->counts[$event->type->value] = ($this->counts[$event->type->value] ?? 0) + 1;

        if ($event->bytes !== null) {
            $this->bytesWritten += $event->bytes;
        }

        if ($event->durationMicros > 0.0) {
            $this->durationSum += $event->durationMicros;
            $this->durationSamples++;
            $this->durationMax = max($this->durationMax, $event->durationMicros);
        }
    }

    /**
     * @param CacheEventType $type
     * @return int
     */
    public function count(CacheEventType $type): int
    {
        return $this->counts[$type->value] ?? 0;
    }

    /**
     * @return float
     */
    public function hitRate(): float
    {
        $hits = $this->count(CacheEventType::Hit);
        $lookups = $hits + $this->count(CacheEventType::Miss);

        return $lookups === 0 ? 0.0 : $hits / $lookups;
    }

    /**
     * @return array<string, int|float>
     */
    public function snapshot(): array
    {
        return [
            'hits'           => $this->count(CacheEventType::Hit),
            'misses'         => $this->count(CacheEventType::Miss),
            'hit_rate'       => round($this->hitRate(), 4),
            'writes'         => $this->count(CacheEventType::Write),
            'deletes'        => $this->count(CacheEventType::Delete),
            'failures'       => $this->count(CacheEventType::Failure),
            'promotions'     => $this->count(CacheEventType::Promotion),
            'stale_served'   => $this->count(CacheEventType::StaleServed),
            'refreshes'      => $this->count(CacheEventType::Refresh),
            'lock_contended' => $this->count(CacheEventType::LockContended),
            'bytes_written'  => $this->bytesWritten,
            'avg_micros'     => $this->durationSamples === 0 ? 0.0 : round($this->durationSum / $this->durationSamples, 2),
            'max_micros'     => round($this->durationMax, 2),
        ];
    }
}
