<?php

declare(strict_types=1);

/**
 * Tiered caching benchmark.
 *
 * Compares a store used alone with the same store behind an in-process L1
 * (TieredStore: ArrayStore L1 -> store L2), under one seeded, Zipf-distributed
 * read-heavy workload. Every configuration replays the identical operation
 * sequence, and L1 starts cold in every run.
 *
 *   php bench/tiered.php [--ops=50000] [--runs=5] [--keys=1000] [--value-bytes=1024] [--write-ratio=0.05] [--out=tiered-benchmark]
 *
 * Writes results/<out>.{json,md}. Uses a dedicated redis-server on
 * port 6391 and an SQLite file in the system temp directory; nothing else.
 */

// Monitoring would measure the monitor, not the cache.
putenv('CACHEER_MONITOR_AUTO_REGISTER=false');

require __DIR__ . '/../vendor/autoload.php';

use Evidence\CountingStore;
use Evidence\Environment;
use Evidence\RedisServer;
use Evidence\Stats;
use Silviooosilva\CacheerPhp\Cacheer;
use Silviooosilva\CacheerPhp\Contracts\Store;
use Silviooosilva\CacheerPhp\Kernel\Key;
use Silviooosilva\CacheerPhp\Kernel\Ttl;
use Silviooosilva\CacheerPhp\Stores\ArrayStore;
use Silviooosilva\CacheerPhp\Stores\DatabaseStore;
use Silviooosilva\CacheerPhp\Stores\RedisStore;
use Silviooosilva\CacheerPhp\Stores\Support\DatabaseStoreSchema;
use Silviooosilva\CacheerPhp\Stores\Support\PredisConnection;
use Silviooosilva\CacheerPhp\Stores\TieredStore;
use Silviooosilva\CacheerPhp\Support\SystemClock;

$options = getopt('', ['ops::', 'runs::', 'keys::', 'value-bytes::', 'write-ratio::', 'seed::', 'out::']);
$ops = (int) ($options['ops'] ?? 50_000);
$runs = (int) ($options['runs'] ?? 5);
$keyCount = (int) ($options['keys'] ?? 1_000);
$valueBytes = (int) ($options['value-bytes'] ?? 1_024);
$writeRatio = (float) ($options['write-ratio'] ?? 0.05);
$seed = (int) ($options['seed'] ?? 42);
$out = preg_replace('/[^a-z0-9-]/', '', strtolower((string) ($options['out'] ?? 'tiered-benchmark'))) ?: 'tiered-benchmark';

$redis = new RedisServer(6391);
$redis->start();
$clock = new SystemClock();
$sqliteFile = sys_get_temp_dir() . '/cacheer-evidence-bench.sqlite';

try {
    $environment = Environment::describe($redis);

    // One deterministic operation sequence, replayed by every configuration.
    $sequence = workload($ops, $keyCount, $writeRatio, $seed);
    $value = substr(str_repeat(hash('sha256', (string) $seed), (int) ceil($valueBytes / 64)), 0, $valueBytes);

    $backends = [
        'Redis'       => static function () use ($redis): Store {
            $client = $redis->client(2.0);
            $client->flushdb();

            return new RedisStore(new PredisConnection($client), 'bench');
        },
        'SQLite file' => static function () use ($sqliteFile, $clock): Store {
            @unlink($sqliteFile);
            $pdo = new PDO('sqlite:' . $sqliteFile);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            DatabaseStoreSchema::migrate($pdo, 'cacheer_store');

            return new DatabaseStore($pdo, clock: $clock);
        },
    ];

    $results = [];
    foreach ($backends as $backend => $makeL2) {
        foreach (['alone' => false, 'tiered (Array L1)' => true] as $shape => $tiered) {
            $samples = [];

            for ($run = 0; $run < $runs; $run++) {
                $l2Store = $makeL2();
                foreach (range(0, $keyCount - 1) as $i) {
                    $l2Store->set(Key::named('item:' . $i), $value, Ttl::forever()); // prefill L2 only
                }

                $l2 = new CountingStore($l2Store);
                $store = $tiered ? new TieredStore(new ArrayStore($clock), $l2, $clock) : $l2;
                $cache = new Cacheer($store, $clock);

                $commandsBefore = $backend === 'Redis' ? redisCommands($redis) : 0;
                $samples[] = measure($cache, $sequence, $value) + [
                    'l2_calls'       => $l2->total(),
                    'l2_gets'        => $l2->calls['get'],
                    'redis_commands' => $backend === 'Redis' ? redisCommands($redis) - $commandsBefore - 1 : null,
                ];
            }

            $results[] = summarize($backend, $shape, $samples, $ops);
        }
    }

    $report = [
        'environment' => $environment,
        'workload'    => [
            'operations' => $ops, 'runs per configuration' => $runs, 'keys' => $keyCount,
            'value size' => $valueBytes . ' bytes', 'write ratio' => $writeRatio,
            'key distribution' => 'Zipf, s = 1.0', 'seed' => $seed,
        ],
        'results'     => $results,
    ];

    @mkdir(__DIR__ . '/../results', 0775, true);
    file_put_contents(__DIR__ . '/../results/' . $out . '.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    file_put_contents(__DIR__ . '/../results/' . $out . '.md', markdown($report));
    echo markdown($report);
} finally {
    $redis->stop();
    @unlink($sqliteFile);
}

/**
 * @return list<array{0: 'get'|'set', 1: string}>
 */
function workload(int $ops, int $keyCount, float $writeRatio, int $seed): array
{
    mt_srand($seed, MT_RAND_MT19937);

    // Cumulative Zipf weights: rank r is requested in proportion to 1/r.
    $cumulative = [];
    $total = 0.0;
    for ($rank = 1; $rank <= $keyCount; $rank++) {
        $total += 1 / $rank;
        $cumulative[] = $total;
    }

    $sequence = [];
    for ($i = 0; $i < $ops; $i++) {
        $target = mt_rand() / mt_getrandmax() * $total;
        $low = 0;
        $high = $keyCount - 1;
        while ($low < $high) {
            $middle = intdiv($low + $high, 2);
            $cumulative[$middle] < $target ? $low = $middle + 1 : $high = $middle;
        }

        $sequence[] = [mt_rand() / mt_getrandmax() < $writeRatio ? 'set' : 'get', 'item:' . $low];
    }

    return $sequence;
}

/**
 * @param list<array{0: 'get'|'set', 1: string}> $sequence
 * @return array{seconds: float, latencies: list<float>}
 */
function measure(Cacheer $cache, array $sequence, string $value): array
{
    $latencies = [];
    $start = hrtime(true);

    foreach ($sequence as [$operation, $key]) {
        $began = hrtime(true);
        $operation === 'get' ? $cache->get($key) : $cache->set($key, $value);
        $latencies[] = (hrtime(true) - $began) / 1_000; // microseconds
    }

    return ['seconds' => (hrtime(true) - $start) / 1e9, 'latencies' => $latencies];
}

function redisCommands(RedisServer $redis): int
{
    $stats = $redis->client(2.0)->info('stats');

    return (int) ($stats['Stats']['total_commands_processed'] ?? $stats['total_commands_processed'] ?? 0);
}

/**
 * @param list<array{seconds: float, latencies: list<float>, l2_calls: int, l2_gets: int, redis_commands: ?int}> $samples
 * @return array<string, mixed>
 */
function summarize(string $backend, string $shape, array $samples, int $ops): array
{
    $throughputs = array_map(static fn (array $s): float => $ops / $s['seconds'], $samples);
    $p = static fn (float $q): float => Stats::median(array_map(static fn (array $s): float => Stats::percentile($s['latencies'], $q), $samples));

    return [
        'backend'              => $backend,
        'shape'                => $shape,
        'ops_per_second'       => round(Stats::median($throughputs)),
        'ops_per_second_range' => [round(min($throughputs)), round(max($throughputs))],
        'p50_us'               => round($p(50), 1),
        'p95_us'               => round($p(95), 1),
        'p99_us'               => round($p(99), 1),
        'l2_calls'             => (int) Stats::median(array_map(static fn (array $s): int => $s['l2_calls'], $samples)),
        'l2_gets'              => (int) Stats::median(array_map(static fn (array $s): int => $s['l2_gets'], $samples)),
        'redis_commands'       => $samples[0]['redis_commands'] === null ? null
            : (int) Stats::median(array_map(static fn (array $s): int => (int) $s['redis_commands'], $samples)),
    ];
}

/**
 * @param array{environment: array<string, string>, workload: array<string, mixed>, results: list<array<string, mixed>>} $report
 */
function markdown(array $report): string
{
    $lines = ['# Tiered caching benchmark', '', '## Environment', '', Environment::markdown($report['environment']), '', '## Workload', ''];
    foreach ($report['workload'] as $label => $value) {
        $lines[] = sprintf('- %s: %s', $label, $value);
    }

    $lines[] = '';
    $lines[] = '## Results (median of runs)';
    $lines[] = '';
    $lines[] = '| Backend | Shape | ops/s (min–max) | p50 µs | p95 µs | p99 µs | L2 calls | Redis commands | vs. alone |';
    $lines[] = '|---|---|---|---|---|---|---|---|---|';

    $baseline = [];
    foreach ($report['results'] as $row) {
        $baseline[$row['backend']] ??= $row['ops_per_second'];
        $lines[] = sprintf(
            '| %s | %s | %s (%s–%s) | %.1f | %.1f | %.1f | %s | %s | %.2f× |',
            $row['backend'],
            $row['shape'],
            number_format($row['ops_per_second']),
            number_format($row['ops_per_second_range'][0]),
            number_format($row['ops_per_second_range'][1]),
            $row['p50_us'],
            $row['p95_us'],
            $row['p99_us'],
            number_format($row['l2_calls']),
            $row['redis_commands'] === null ? '—' : number_format($row['redis_commands']),
            $row['ops_per_second'] / $baseline[$row['backend']],
        );
    }

    return implode("\n", $lines) . "\n";
}
