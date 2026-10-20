<?php

declare(strict_types=1);

namespace Evidence;

/**
 * Small, dependency-free statistics for benchmark samples.
 */
final class Stats
{
    /**
     * @param list<float|int> $values
     */
    public static function percentile(array $values, float $percentile): float
    {
        sort($values);
        $count = count($values);

        if ($count === 0) {
            return 0.0;
        }

        // Nearest-rank: the smallest value with at least $percentile% at or below it.
        $rank = (int) ceil($percentile / 100 * $count);

        return (float) $values[max(0, min($count - 1, $rank - 1))];
    }

    /**
     * @param list<float|int> $values
     */
    public static function median(array $values): float
    {
        sort($values);
        $count = count($values);
        $middle = intdiv($count, 2);

        return $count % 2 === 1 ? (float) $values[$middle] : ($values[$middle - 1] + $values[$middle]) / 2;
    }
}
