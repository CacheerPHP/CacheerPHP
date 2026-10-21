<?php

declare(strict_types=1);

/**
 * Traffic generator for the Cacheer Monitor walkthrough.
 *
 * Produces about a minute of realistic, mixed cache traffic through real stores
 * so every dashboard panel has something true to show: hits and misses,
 * writes, renewals, tag flushes, tiered promotions, stale-while-revalidate
 * refreshes, and — in the middle — a Redis outage behind a ResilientStore.
 *
 * Monitoring is the point here, so cacheerphp/monitor's automatic listener
 * stays on: every cache built below is instrumented without extra wiring.
 *
 *   CACHEER_MONITOR_EVENTS=monitor/var/events.jsonl php monitor/traffic.php [--seconds=60]
 *
 * Values are not captured (the monitor's default); only metadata is recorded.
 */

$events = getenv('CACHEER_MONITOR_EVENTS') ?: __DIR__ . '/var/events.jsonl';
putenv('CACHEER_MONITOR_EVENTS=' . $events);
@mkdir(dirname($events), 0775, true);

require __DIR__ . '/../vendor/autoload.php';

use Evidence\RedisServer;
use Silviooosilva\CacheerPhp\Cacheer;
use Silviooosilva\CacheerPhp\Exceptions\StoreOperationFailedException;
use Silviooosilva\CacheerPhp\Kernel\Key;
use Silviooosilva\CacheerPhp\Kernel\Ttl;
use Silviooosilva\CacheerPhp\Stores\ArrayStore;
use Silviooosilva\CacheerPhp\Stores\FileStore;
use Silviooosilva\CacheerPhp\Stores\RedisStore;
use Silviooosilva\CacheerPhp\Stores\ResilientStore;
use Silviooosilva\CacheerPhp\Stores\Support\DatabaseStoreSchema;
use Silviooosilva\CacheerPhp\Stores\Support\PredisConnection;
use Silviooosilva\CacheerPhp\Support\AfterResponseDeferredExecutor;
use Silviooosilva\CacheerPhp\Support\CircuitBreaker;
use Silviooosilva\CacheerPhp\Support\SystemClock;

$options = getopt('', ['seconds::']);
$seconds = (float) ($options['seconds'] ?? 60);

$redis = new RedisServer(6391);
$redis->start();
$clock = new SystemClock();
$work = sys_get_temp_dir() . '/cacheer-evidence-traffic-' . getmypid();
@mkdir($work, 0775, true);

try {
    // A file-backed catalog, scoped per tenant.
    $catalog = Cacheer::file($work . '/catalog')->in('tenant-acme');

    // Sessions in SQLite.
    $pdo = new PDO('sqlite:' . $work . '/sessions.sqlite');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    DatabaseStoreSchema::migrate($pdo, 'cacheer_store');
    $sessions = Cacheer::database($pdo);

    // Products: an in-process L1 in front of Redis. Another worker has already
    // rendered them into the shared L2, so this worker's first reads promote.
    $productsL2 = new RedisStore(new PredisConnection($redis->client(1.0)), 'products');
    foreach (range(1, 30) as $id) {
        $productsL2->set(Key::named('product:' . $id), 'rendered card', Ttl::seconds(300));
    }
    $products = Cacheer::tiered(new ArrayStore($clock), $productsL2);

    // Profiles: Redis behind a circuit breaker, falling back to files.
    $breaker = new CircuitBreaker($clock, failureThreshold: 3, recoverySeconds: 3.0);
    $profiles = new Cacheer(new ResilientStore(
        new RedisStore(new PredisConnection($redis->client(0.2)), 'profiles'),
        new FileStore($work . '/profiles-fallback', clock: $clock),
        $breaker,
        $clock,
    ), $clock);

    // A report refreshed in the background with stale-while-revalidate.
    $executor = new AfterResponseDeferredExecutor();
    $reports = new Cacheer(new ArrayStore($clock), $clock, $executor);

    mt_srand(7);
    $started = microtime(true);
    $outageFrom = $seconds * 0.45;
    $outageTo = $seconds * 0.6;
    $frozen = false;
    $tick = 0;

    echo "Generating traffic for {$seconds}s into {$events}\n";

    while (($elapsed = microtime(true) - $started) < $seconds) {
        $tick++;

        // Redis outage window for the profiles cache (and the tiered L2).
        if (!$frozen && $elapsed >= $outageFrom && $elapsed < $outageTo) {
            $redis->freeze();
            $frozen = true;
            printf("[%5.1fs] Redis frozen — profiles fail over to files\n", $elapsed);
        } elseif ($frozen && $elapsed >= $outageTo) {
            $redis->thaw();
            $frozen = false;
            printf("[%5.1fs] Redis back — breaker will probe and recover\n", $elapsed);
        }

        // Catalog: Zipf-ish product pages, mostly hits once warm.
        $sku = (int) floor(40 / (1 + mt_rand(0, 39)));
        $catalog->remember('product:' . $sku, mt_rand(30, 600), static fn (): array => ['sku' => $sku, 'price' => mt_rand(5, 500)]);
        if ($tick % 9 === 0) {
            $catalog->touch('product:' . $sku, 900);
        }
        if ($tick % 40 === 0) {
            $catalog->tag('product:' . $sku, 'promotions');
        }
        if ($tick % 160 === 0) {
            $catalog->flushTag('promotions');
        }

        // Sessions: short-lived, often missing.
        $session = 'session:' . mt_rand(1, 25);
        if ($sessions->get($session) === null) {
            $sessions->set($session, ['user' => mt_rand(1, 500)], mt_rand(20, 120));
        }
        if ($tick % 30 === 0) {
            $sessions->delete($session);
        }

        // Products through the tiered cache (skipped while its L2 is frozen).
        if (!$frozen) {
            $products->remember('product:' . mt_rand(1, 30), 300, static fn (): string => 'rendered card');
        }

        // Profiles through the resilient cache, including during the outage.
        try {
            $profiles->remember('profile:' . mt_rand(1, 15), 120, static fn (): string => 'profile');
            if ($tick % 25 === 0) {
                $profiles->increment('profile-views');
            }
        } catch (StoreOperationFailedException) {
            // Counters fail closed while the primary is down; that is expected.
        }

        // A dashboard report: fresh for 2s, served stale up to 10s while refreshing.
        $reports->flexible('report:daily', 2, 10, static fn (): string => 'report built at ' . date('H:i:s'));
        $executor->flush(); // what a worker does after each request

        usleep(mt_rand(15_000, 45_000));
    }

    printf("Done: %d iterations.\n", $tick);
} finally {
    $redis->stop();
    exec('rm -rf ' . escapeshellarg($work));
}
