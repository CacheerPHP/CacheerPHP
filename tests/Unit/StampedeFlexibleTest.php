<?php

use Silviooosilva\CacheerPhp\Cacheer;
use Silviooosilva\CacheerPhp\Core\Connect;
use Silviooosilva\CacheerPhp\Exceptions\CacheInvalidArgumentException;

/*
|--------------------------------------------------------------------------
| Cache-stampede protection & stale-while-revalidate
|--------------------------------------------------------------------------
|
| remember() now serialises a concurrent miss behind a single-flight lock, so
| the callback runs once. flexible() adds stale-while-revalidate: fresh values
| are served directly, stale ones are served while a single worker refreshes,
| and expired ones are recomputed.
|
*/

dataset('swr drivers', ['array', 'file']);

afterEach(function () {
    Cacheer::resetInstance();

    foreach (glob(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cacheer-sf-*') ?: [] as $dir) {
        sf_rrmdir($dir);
    }

    try {
        $pdo = Connect::getInstance();
        if ($pdo instanceof PDO) {
            $pdo->exec('DROP TABLE IF EXISTS cacheer_locks');
        }
    } catch (\Throwable) {
        // No DB configured for this test.
    }
});

it('runs remember() callback once under a concurrent miss', function () {
    if (!function_exists('pcntl_fork')) {
        $this->markTestSkipped('The pcntl extension is required for the stampede test.');
    }

    $dir = sf_tempdir();
    $counter = $dir . DIRECTORY_SEPARATOR . 'cb-count';
    @touch($counter);

    $children = 6;
    $pids = [];

    for ($i = 0; $i < $children; $i++) {
        $pid = pcntl_fork();

        if ($pid === -1) {
            $this->markTestSkipped('Unable to fork a worker process.');
        }

        if ($pid === 0) {
            $cache = new Cacheer(['cacheDir' => $dir]);
            $value = $cache->remember('expensive', 60, function () use ($counter) {
                // Record the call, then dwell so the other workers overlap.
                file_put_contents($counter, 'x', FILE_APPEND | LOCK_EX);
                usleep(300_000);
                return 'computed-value';
            });
            exit($value === 'computed-value' ? 0 : 1);
        }

        $pids[] = $pid;
    }

    // Every child asserts it saw 'computed-value' via its exit code; a child
    // that failed (or was killed by a signal) must not be silently ignored.
    $childFailures = 0;
    foreach ($pids as $pid) {
        pcntl_waitpid($pid, $status);
        if (!pcntl_wifexited($status) || pcntl_wexitstatus($status) !== 0) {
            $childFailures++;
        }
    }

    // Every worker got the value, and the callback ran exactly once despite
    // six concurrent misses.
    expect($childFailures)->toBe(0)
        ->and(strlen((string) file_get_contents($counter)))->toBe(1)
        ->and((new Cacheer(['cacheDir' => $dir]))->getCache('expensive'))->toBe('computed-value');
});

it('serves a fresh flexible() value without recomputing', function (string $driver) {
    $cache = sf_cache($driver);
    $calls = 0;
    $callback = function () use (&$calls) {
        $calls++;
        return 'v' . $calls;
    };

    $first = $cache->flexible('home', 10, 20, $callback); // cold → compute
    $second = $cache->flexible('home', 10, 20, $callback); // fresh → no compute

    expect($first)->toBe('v1')
        ->and($second)->toBe('v1')
        ->and($calls)->toBe(1);
})->with('swr drivers');

it('serves stale then refreshes a flexible() value', function () {
    $cache = sf_cache('file');
    $calls = 0;
    $callback = function () use (&$calls) {
        $calls++;
        return 'v' . $calls;
    };

    // A 2s fresh window leaves margin so the immediate re-read below can't
    // straddle a 1s wall-clock boundary and be misread as stale.
    expect($cache->flexible('k', 2, 30, $callback))->toBe('v1'); // cold → compute
    expect($cache->flexible('k', 2, 30, $callback))->toBe('v1'); // still fresh
    expect($calls)->toBe(1);

    sleep(3); // now past fresh_until (2s), well within stale (30s)

    // A single caller wins the refresh lock and recomputes inline.
    expect($cache->flexible('k', 2, 30, $callback))->toBe('v2')
        ->and($calls)->toBe(2);

    // Fresh again after the refresh — no further recompute.
    expect($cache->flexible('k', 2, 30, $callback))->toBe('v2')
        ->and($calls)->toBe(2);
});

it('recomputes a flexible() value after the stale horizon', function () {
    $cache = sf_cache('file');
    $calls = 0;
    $callback = function () use (&$calls) {
        $calls++;
        return 'v' . $calls;
    };

    expect($cache->flexible('k2', 1, 2, $callback))->toBe('v1');

    sleep(3); // past stale_until (2s) → hard miss

    expect($cache->flexible('k2', 1, 2, $callback))->toBe('v2')
        ->and($calls)->toBe(2);
});

it('rejects invalid flexible() horizons', function (int $fresh, int $stale) {
    $cache = sf_cache('array');

    expect(fn () => $cache->flexible('k', $fresh, $stale, fn () => 'x'))
        ->toThrow(CacheInvalidArgumentException::class);
})->with([
    'equal horizons'   => [5, 5],
    'stale below fresh' => [10, 5],
    'negative fresh'   => [-1, 10],
    'negative stale'   => [5, -1],
]);

/**
 * Build a Cacheer for the given driver.
 */
function sf_cache(string $driver): Cacheer
{
    if ($driver === 'file') {
        return new Cacheer(['cacheDir' => sf_tempdir()]);
    }

    $cache = new Cacheer();
    $cache->setDriver()->useArrayDriver();
    return $cache;
}

/**
 * Create (and track) a temp cache directory.
 */
function sf_tempdir(): string
{
    $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cacheer-sf-' . bin2hex(random_bytes(4));
    @mkdir($dir, 0775, true);
    return $dir;
}

/**
 * Recursively remove a directory.
 */
function sf_rrmdir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    foreach (scandir($dir) ?: [] as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        is_dir($path) ? sf_rrmdir($path) : @unlink($path);
    }
    @rmdir($dir);
}
