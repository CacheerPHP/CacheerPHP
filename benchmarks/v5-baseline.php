<?php

declare(strict_types=1);

use Silviooosilva\CacheerPhp\Cacheer;
use Silviooosilva\CacheerPhp\Utils\CacheDriver;

require dirname(__DIR__) . '/vendor/autoload.php';

$iterations = max(1, (int) (getenv('CACHEER_BENCH_ITERATIONS') ?: 1000));
$driverNames = array_values(array_filter(array_map(
    'trim',
    explode(',', (string) (getenv('CACHEER_BENCH_DRIVERS') ?: 'array,file')),
)));
$runId = bin2hex(random_bytes(6));
$namespace = 'cacheer-benchmark-' . $runId;
$temporaryDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $namespace;
$payload = str_repeat('cacheer-', 128);
$results = [];

foreach ($driverNames as $driverName) {
    try {
        $cache = createCache($driverName, $temporaryDirectory);
        $keys = [];

        $write = measure(function () use ($cache, $iterations, $namespace, $payload, &$keys): void {
            for ($index = 0; $index < $iterations; $index++) {
                $key = 'entry-' . $index;
                $keys[] = $key;
                if (!$cache->putCache($key, $payload, $namespace, 3600)) {
                    throw new RuntimeException('Write failed for key ' . $key);
                }
            }
        });

        $read = measure(function () use ($cache, $keys, $namespace, $payload): void {
            foreach ($keys as $key) {
                if ($cache->getCache($key, $namespace) !== $payload) {
                    throw new RuntimeException('Read mismatch for key ' . $key);
                }
            }
        });

        $delete = measure(function () use ($cache, $keys, $namespace): void {
            foreach ($keys as $key) {
                if (!$cache->clearCache($key, $namespace)) {
                    throw new RuntimeException('Delete failed for key ' . $key);
                }
            }
        });

        $results[$driverName] = [
            'write_ops_per_second'  => operationsPerSecond($iterations, $write),
            'read_ops_per_second'   => operationsPerSecond($iterations, $read),
            'delete_ops_per_second' => operationsPerSecond($iterations, $delete),
            'write_seconds'         => round($write, 6),
            'read_seconds'          => round($read, 6),
            'delete_seconds'        => round($delete, 6),
        ];
    } catch (Throwable $exception) {
        $results[$driverName] = [
            'error' => $exception->getMessage(),
        ];
    }
}

removeDirectory($temporaryDirectory);

echo json_encode([
    'format'        => 'cacheer-v5-baseline-v1',
    'generated_at'  => gmdate(DATE_ATOM),
    'php'           => PHP_VERSION,
    'platform'      => PHP_OS_FAMILY,
    'iterations'    => $iterations,
    'payload_bytes' => strlen($payload),
    'results'       => $results,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;

/**
 * @return float Seconds elapsed.
 */
function measure(Closure $operation): float
{
    $started = hrtime(true);
    $operation();

    return (hrtime(true) - $started) / 1_000_000_000;
}

function operationsPerSecond(int $iterations, float $seconds): int
{
    return $seconds <= 0.0 ? 0 : (int) round($iterations / $seconds);
}

function createCache(string $driver, string $temporaryDirectory): Cacheer
{
    $cache = new Cacheer(['cacheDir' => $temporaryDirectory]);
    $selector = new CacheDriver($cache);
    $selector->logPath = $temporaryDirectory . DIRECTORY_SEPARATOR . 'cacheer.log';

    return match ($driver) {
        'array'    => $selector->useArrayDriver(),
        'file'     => $selector->useFileDriver(),
        'database' => $selector->useDatabaseDriver(),
        'redis'    => $selector->useRedisDriver(),
        default    => throw new InvalidArgumentException('Unsupported benchmark driver: ' . $driver),
    };
}

function removeDirectory(string $directory): void
{
    if (!is_dir($directory)) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($iterator as $item) {
        $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    }

    @rmdir($directory);
}
