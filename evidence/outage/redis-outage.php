<?php

declare(strict_types=1);

/**
 * Redis outage demonstration.
 *
 * Runs a ResilientStore (Redis primary, FileStore fallback) against a dedicated
 * redis-server and takes it through real failures:
 *
 *   1. healthy   — normal traffic, a counter, a lock;
 *   2. frozen    — the Redis process stops responding (SIGSTOP): clients time
 *                  out, the breaker opens, reads fail over to the fallback;
 *                  a key is deleted and another changed during the outage;
 *   3. recovered — the process resumes (SIGCONT); after the breaker's recovery
 *                  window the primary is reconciled, so nothing deleted or
 *                  changed during the outage comes back;
 *   4. killed    — the process is gone: connections are refused, which is
 *                  detected far faster than a timeout.
 *
 *   php outage/redis-outage.php
 *
 * Writes results/redis-outage.md. Uses real time, so it takes a few seconds.
 */

putenv('CACHEER_MONITOR_AUTO_REGISTER=false');

require __DIR__ . '/../vendor/autoload.php';

use Evidence\Environment;
use Evidence\RedisServer;
use Silviooosilva\CacheerPhp\Cacheer;
use Silviooosilva\CacheerPhp\Exceptions\StoreOperationFailedException;
use Silviooosilva\CacheerPhp\Stores\FileStore;
use Silviooosilva\CacheerPhp\Stores\RedisStore;
use Silviooosilva\CacheerPhp\Stores\ResilientStore;
use Silviooosilva\CacheerPhp\Stores\Support\PredisConnection;
use Silviooosilva\CacheerPhp\Support\CircuitBreaker;
use Silviooosilva\CacheerPhp\Support\SystemClock;

const TIMEOUT = 0.25;          // Redis connect/read timeout, seconds
const FAILURE_THRESHOLD = 3;   // consecutive failures that open the breaker
const RECOVERY_SECONDS = 2.0;  // how long the breaker stays open before probing

$redis = new RedisServer(6391);
$redis->start();
$clock = new SystemClock();
$fallbackDir = sys_get_temp_dir() . '/cacheer-evidence-fallback-' . getmypid();

$breaker = new CircuitBreaker($clock, failureThreshold: FAILURE_THRESHOLD, recoverySeconds: RECOVERY_SECONDS);
$primary = new RedisStore(new PredisConnection($redis->client(TIMEOUT)), 'demo');
$resilient = new ResilientStore($primary, new FileStore($fallbackDir, clock: $clock), $breaker, $clock);
$cache = new Cacheer($resilient, $clock);

$log = [];
$started = microtime(true);
$phase = '';

/**
 * Runs one request and records how long it took, what it returned, and the
 * breaker state afterwards.
 */
$step = static function (string $operation, callable $call) use (&$log, &$phase, $started, $resilient): mixed {
    $began = microtime(true);

    try {
        $result = $call();
        $outcome = describe($result);
    } catch (StoreOperationFailedException $exception) {
        $result = null;
        $outcome = 'refused: ' . $exception->getMessage();
    }

    $log[] = [
        'phase'     => $phase,
        't'         => sprintf('%.2f', microtime(true) - $started),
        'operation' => $operation,
        'outcome'   => $outcome,
        'ms'        => sprintf('%.1f', (microtime(true) - $began) * 1000),
        'breaker'   => $resilient->health()['state'],
    ];

    return $result;
};

try {
    $environment = Environment::describe($redis);

    $phase = '1 healthy';
    $step("set('user:1', 'Ada')", fn () => $cache->set('user:1', 'Ada'));
    $step("set('session:9', 'active')", fn () => $cache->set('session:9', 'active'));
    $step("increment('logins')", fn () => $cache->increment('logins'));
    $step("remember('report', …)", fn () => $cache->remember('report', 300, static fn (): string => 'built while healthy'));
    $step("lock('nightly')->acquire()", function () use ($cache): bool {
        $lock = $cache->lock('nightly', 30);
        $acquired = $lock->acquire();
        $lock->release();

        return $acquired;
    });

    $phase = '2 frozen';
    $redis->freeze();
    for ($i = 1; $i <= FAILURE_THRESHOLD + 2; $i++) {
        $step("get('user:1')", fn () => $cache->get('user:1'));
    }
    $step("delete('session:9')", fn () => $cache->delete('session:9'));
    $step("set('user:1', 'Ada Lovelace')", fn () => $cache->set('user:1', 'Ada Lovelace'));
    $step("get('user:1')", fn () => $cache->get('user:1'));
    $step("increment('logins')", fn () => $cache->increment('logins'));
    $step("lock('nightly')->acquire()", fn () => $cache->lock('nightly', 30)->acquire());
    $step("remember('dashboard', …)", fn () => $cache->remember('dashboard', 300, static fn (): string => 'built during the outage'));

    $phase = '3 recovered';
    $redis->thaw();
    usleep((int) (RECOVERY_SECONDS * 1_000_000) + 100_000);
    $step("get('session:9')", fn () => $cache->get('session:9', 'miss'));
    $step("get('user:1')", fn () => $cache->get('user:1', 'miss'));
    $step("increment('logins')", fn () => $cache->increment('logins'));
    $step("get('report')", fn () => $cache->get('report', 'miss'));

    $phase = '4 killed';
    $redis->kill();
    for ($i = 1; $i <= FAILURE_THRESHOLD + 1; $i++) {
        $step("get('report')", fn () => $cache->get('report', 'miss'));
    }

    $report = render($environment, $log);
    @mkdir(__DIR__ . '/../results', 0775, true);
    file_put_contents(__DIR__ . '/../results/redis-outage.md', $report);
    echo $report;
} finally {
    $redis->stop();
    exec('rm -rf ' . escapeshellarg($fallbackDir));
}

function describe(mixed $result): string
{
    return match (true) {
        $result === null  => 'ok',
        is_bool($result)  => $result ? 'true' : 'false',
        is_int($result)   => (string) $result,
        default           => "'" . (string) $result . "'",
    };
}

/**
 * @param array<string, string> $environment
 * @param list<array<string, string>> $log
 */
function render(array $environment, array $log): string
{
    $lines = [
        '# Redis outage demonstration',
        '',
        Environment::markdown($environment),
        '',
        sprintf(
            'ResilientStore: Redis primary (connect/read timeout %.2fs) → FileStore fallback; breaker opens after %d failures and probes again after %.1fs.',
            TIMEOUT,
            FAILURE_THRESHOLD,
            RECOVERY_SECONDS,
        ),
        '',
        '| Phase | t (s) | Request | Result | Latency (ms) | Breaker after |',
        '|---|---|---|---|---|---|',
    ];

    foreach ($log as $row) {
        $lines[] = sprintf('| %s | %s | `%s` | %s | %s | %s |', $row['phase'], $row['t'], $row['operation'], $row['outcome'], $row['ms'], $row['breaker']);
    }

    return implode("\n", $lines) . "\n";
}
