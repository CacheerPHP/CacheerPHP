<?php

declare(strict_types=1);

/**
 * Library overhead, layer by layer.
 *
 * The tiered benchmark measures L1 against L2; this measures what CacheerPHP
 * itself adds on each call, so performance work can target real costs. Every
 * figure is the median nanoseconds per operation over 7 batches.
 *
 *   php bench/overhead.php [--iterations=20000] [--out=overhead]
 *
 * Writes results/<out>.{json,md}. Uses a dedicated redis-server on port 6391.
 */

putenv('CACHEER_MONITOR_AUTO_REGISTER=false');

require __DIR__ . '/../vendor/autoload.php';

use Evidence\CountingStore;
use Evidence\Environment;
use Evidence\RedisServer;
use Evidence\Stats;
use Silviooosilva\CacheerPhp\Cacheer;
use Silviooosilva\CacheerPhp\Config\PipelineConfig;
use Silviooosilva\CacheerPhp\Kernel\Key;
use Silviooosilva\CacheerPhp\Kernel\Scope;
use Silviooosilva\CacheerPhp\Kernel\Ttl;
use Silviooosilva\CacheerPhp\Observability\EventBus;
use Silviooosilva\CacheerPhp\Observability\MetricsCollector;
use Silviooosilva\CacheerPhp\Storage\KeyEncoder\HashingKeyEncoder;
use Silviooosilva\CacheerPhp\Stores\ArrayStore;
use Silviooosilva\CacheerPhp\Stores\FileStore;
use Silviooosilva\CacheerPhp\Stores\InstrumentedStore;
use Silviooosilva\CacheerPhp\Stores\RedisStore;
use Silviooosilva\CacheerPhp\Stores\Support\PredisConnection;
use Silviooosilva\CacheerPhp\Stores\Support\StoredRecord;
use Silviooosilva\CacheerPhp\Stores\TieredStore;
use Silviooosilva\CacheerPhp\Support\SystemClock;

$options = getopt('', ['iterations::', 'out::']);
$iterations = (int) ($options['iterations'] ?? 20_000);
$out = preg_replace('/[^a-z0-9-]/', '', strtolower((string) ($options['out'] ?? 'overhead'))) ?: 'overhead';

/**
 * @return float median nanoseconds per call
 */
function measure(callable $operation, int $iterations): float
{
    for ($i = 0; $i < min(1_000, $iterations); $i++) {
        $operation(); // warm up
    }

    $batches = [];
    for ($batch = 0; $batch < 7; $batch++) {
        $start = hrtime(true);
        for ($i = 0; $i < $iterations; $i++) {
            $operation();
        }
        $batches[] = (hrtime(true) - $start) / $iterations;
    }

    return Stats::median($batches);
}

$redis = new RedisServer(6391);
$redis->start();
$clock = new SystemClock();
$tmp = sys_get_temp_dir() . '/cacheer-evidence-overhead-' . getmypid();
$rows = [];
$add = static function (string $group, string $label, float $ns, string $note = '') use (&$rows): void {
    $rows[] = ['group' => $group, 'measurement' => $label, 'ns_per_op' => round($ns), 'note' => $note];
};

try {
    $environment = Environment::describe($redis);
    $small = str_repeat('a', 1_024);
    $large = str_repeat('b', 65_536);

    // --- Keys and scopes -----------------------------------------------------
    $tenant = Scope::named('tenant');
    $prebuilt = Key::named('product:42');
    $add('keys', 'Key::named()', measure(static fn () => Key::named('product:42'), $iterations));
    $add('keys', 'Key::within(scope)', measure(static fn () => $prebuilt->within($tenant), $iterations));
    $add('keys', 'Key::identity()', measure(static fn () => $prebuilt->identity(), $iterations));
    $encoder = new HashingKeyEncoder();
    $scoped = $prebuilt->within($tenant);
    $add('keys', 'HashingKeyEncoder::encode()', measure(static fn () => $encoder->encode($scoped), $iterations), 'sha256 + readable slug, every backend call');

    // --- In-process call path --------------------------------------------------
    $array = new ArrayStore($clock);
    $array->set($prebuilt, $small, Ttl::forever());
    $array->set($scoped, $small, Ttl::forever());
    $cache = new Cacheer($array, $clock);
    $add('call path', 'ArrayStore::get(Key) — raw store', measure(static fn () => $array->get($prebuilt), $iterations));
    $add('call path', 'Cacheer::get(string)', measure(static fn () => $cache->get('product:42'), $iterations), 'Key::named + run() wrapper');
    $add('call path', 'Cacheer::in(tenant)->get(string)', measure(static fn () => $cache->in('tenant')->get('product:42'), $iterations), 'builds a scoped view per call');
    $tenantCache = $cache->in('tenant');
    $add('call path', 'scoped view reused ->get(string)', measure(static fn () => $tenantCache->get('product:42'), $iterations));
    $add('call path', 'Cacheer::remember() hit', measure(static fn () => $cache->remember('product:42', 60, static fn () => 'x'), $iterations));
    $add('call path', 'Cacheer::set(string)', measure(static fn () => $cache->set('product:42', $small), $iterations));

    // --- Storage pipeline ------------------------------------------------------
    $codec = PipelineConfig::default()->codec();
    foreach (['1 KiB' => $small, '64 KiB' => $large] as $size => $value) {
        $blob = $codec->encode($value);
        $add('pipeline', "EnvelopeCodec::encode() {$size}", measure(static fn () => $codec->encode($value), $iterations));
        $add('pipeline', "EnvelopeCodec::decode() {$size}", measure(static fn () => $codec->decode($blob), $iterations));
        $record = StoredRecord::forKey($scoped, 1_700_000_000, null, $blob)->toString();
        $add('pipeline', "StoredRecord::fromString() {$size}", measure(static fn () => StoredRecord::fromString($record), $iterations), 'JSON header + value copy');
    }

    // --- Redis -----------------------------------------------------------------
    $client = $redis->client(2.0);
    $client->set('raw', $small);
    $redisStore = new RedisStore(new PredisConnection($client), 'overhead');
    $redisStore->set($prebuilt, $small, Ttl::forever());
    $redisCache = new Cacheer($redisStore, $clock);
    $redisIterations = max(2_000, intdiv($iterations, 5));
    $rawGet = measure(static fn () => $client->get('raw'), $redisIterations);
    $add('redis', 'Predis GET — network floor', $rawGet);
    $add('redis', 'RedisStore::get(Key)', measure(static fn () => $redisStore->get($prebuilt), $redisIterations));
    $add('redis', 'Cacheer(redis)::get(string)', measure(static fn () => $redisCache->get('product:42'), $redisIterations));

    // Batches of 100: one round trip each when batched natively.
    $batch = [];
    for ($i = 0; $i < 100; $i++) {
        $batch['batch:' . $i] = $small;
    }
    $add('redis', 'setMany(100)', measure(static fn () => $redisCache->setMany($batch, 60), 200));
    $add('redis', 'many(100)', measure(static fn () => $redisCache->many(array_keys($batch)), 200));
    $add('redis', 'setMany(100) + deleteMany(100)', measure(static function () use ($redisCache, $batch): void {
        $redisCache->setMany($batch, 60);
        $redisCache->deleteMany(array_keys($batch));
    }, 200));

    // Round trips for one cold remember(): count backend calls and Redis commands.
    $counting = new CountingStore($redisStore);
    $before = (int) $client->info('stats')['Stats']['total_commands_processed'];
    (new Cacheer($redisStore, $clock))->remember('cold:' . bin2hex(random_bytes(4)), 60, static fn () => 'v');
    $commands = (int) $client->info('stats')['Stats']['total_commands_processed'] - $before - 1;
    $add('redis', 'cold remember(): Redis commands', (float) $commands, 'count, not ns; the lock-release script counts as 2');

    // --- Tiered L1 hit -----------------------------------------------------------
    $tiered = new Cacheer(new TieredStore(new ArrayStore($clock), $redisStore, $clock), $clock);
    $tiered->get('product:42'); // promote
    $add('tiered', 'TieredStore L1 hit via Cacheer::get', measure(static fn () => $tiered->get('product:42'), $iterations));

    // L1 memory in a long-running worker: 50,000 expiring writes, then an hour
    // later (fake clock) the worker keeps writing. L2 is Redis, so the PHP memory
    // retained is L1's alone.
    require_once __DIR__ . '/../../tests/Support/FakeClock.php';
    $fakeClock = new \Tests\Support\FakeClock();
    gc_collect_cycles();
    $memoryBefore = memory_get_usage();
    $worker = new Cacheer(new TieredStore(new ArrayStore($fakeClock), new RedisStore(new PredisConnection($client), 'memory', clock: $fakeClock), $fakeClock), $fakeClock);
    for ($i = 0; $i < 50_000; $i++) {
        $worker->set('job:' . $i, $small, 30);
    }
    $fakeClock->advance(3_600);
    for ($i = 0; $i < 100; $i++) {
        $worker->set('live:' . $i, $small, 30);
    }
    gc_collect_cycles();
    $add('memory', 'tiered worker: KiB retained after 50k writes expired', (memory_get_usage() - $memoryBefore) / 1024, 'KiB, not ns');
    unset($worker);
    $client->flushdb();

    // --- File store ----------------------------------------------------------------
    $file = Cacheer::file($tmp);
    $file->set('product:42', $small);
    $add('file', 'Cacheer::file()->get() 1 KiB', measure(static fn () => $file->get('product:42'), max(2_000, intdiv($iterations, 5))));

    // --- Monitoring --------------------------------------------------------------
    $bus = new EventBus();
    $bus->listen((new MetricsCollector())->record(...));
    $rowsValue = [];
    for ($i = 0; $i < 500; $i++) {
        $rowsValue['field' . $i] = ['id' => $i, 'name' => 'item ' . $i, 'tags' => ['a', 'b']];
    }
    $plainRows = new ArrayStore($clock);
    $plainRows->set($prebuilt, $rowsValue, Ttl::forever());
    $plainRowsCache = new Cacheer($plainRows, $clock);
    $add('monitoring', 'plain get() hit 500-row array', measure(static fn () => $plainRowsCache->get('product:42'), $iterations));

    foreach (['1 KiB' => $small, '64 KiB' => $large, '500-row array' => $rowsValue] as $size => $value) {
        $plain = new ArrayStore($clock);
        $plain->set($prebuilt, $value, Ttl::forever());
        $instrumented = new Cacheer(new InstrumentedStore($plain, $bus), $clock);
        $add('monitoring', "instrumented get() hit {$size}", measure(static fn () => $instrumented->get('product:42'), $iterations), '');
    }

    $report = ['environment' => $environment, 'iterations' => $iterations, 'results' => $rows];
    @mkdir(__DIR__ . '/../results', 0775, true);
    file_put_contents(__DIR__ . '/../results/' . $out . '.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

    $lines = ['# Library overhead', '', Environment::markdown($environment), '', "Median ns per operation over 7 batches of {$iterations} (Redis/File: fewer).", '', '| Group | Measurement | ns/op | Note |', '|---|---|---|---|'];
    foreach ($rows as $row) {
        $lines[] = sprintf('| %s | %s | %s | %s |', $row['group'], $row['measurement'], number_format($row['ns_per_op']), $row['note']);
    }
    file_put_contents(__DIR__ . '/../results/' . $out . '.md', implode("\n", $lines) . "\n");
    echo implode("\n", $lines), "\n";
} finally {
    $redis->stop();
    exec('rm -rf ' . escapeshellarg($tmp));
}
