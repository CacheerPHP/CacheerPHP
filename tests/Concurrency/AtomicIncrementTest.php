<?php

use Silviooosilva\CacheerPhp\Cacheer;
use Silviooosilva\CacheerPhp\Core\Connect;

/*
|--------------------------------------------------------------------------
| Atomic increment / decrement
|--------------------------------------------------------------------------
|
| increment()/decrement() are read-modify-write and now serialize behind a
| per-key lock when the driver supports it. These tests assert the existing
| semantics are preserved across drivers, and that concurrent processes no
| longer lose updates.
|
*/

dataset('counter drivers', ['array', 'file', 'database']);

afterEach(function () {
    Cacheer::resetInstance();

    foreach (glob(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cacheer-incr-*') ?: [] as $dir) {
        atomic_rrmdir($dir);
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

it('preserves increment/decrement semantics under the lock', function (string $driver) {
    $cache = atomic_cache($driver);

    // Physically remove the keys we use (DB rows persist across runs, and a
    // soft flush would leave stale rows behind).
    foreach (['counter', 'zero', 'absent', 'budget'] as $key) {
        $cache->clearCache($key);
    }

    // Existing numeric key: increment then decrement.
    $cache->putCache('counter', 5);
    expect($cache->increment('counter', 2))->toBeTrue()
        ->and((int) $cache->getCache('counter'))->toBe(7)
        ->and($cache->decrement('counter', 3))->toBeTrue()
        ->and((int) $cache->getCache('counter'))->toBe(4);

    // Zero is a valid stored value (a hit), not "missing".
    $cache->putCache('zero', 0);
    expect($cache->increment('zero', 1))->toBeTrue()
        ->and((int) $cache->getCache('zero'))->toBe(1);

    // Missing key with no default: returns false and is NOT created.
    expect($cache->increment('absent'))->toBeFalse()
        ->and($cache->has('absent'))->toBeFalse();

    // Missing key with a default: seeded as (default + amount).
    expect($cache->increment('budget', 10, '', 100))->toBeTrue()
        ->and((int) $cache->getCache('budget'))->toBe(110);
})->with('counter drivers');

it('does not lose updates across concurrent processes (file driver)', function () {
    if (!function_exists('pcntl_fork')) {
        $this->markTestSkipped('The pcntl extension is required for the concurrency test.');
    }

    $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cacheer-incr-' . bin2hex(random_bytes(4));
    @mkdir($dir, 0775, true);

    $cache = new Cacheer(['cacheDir' => $dir]);
    $cache->putCache('counter', 0);

    $children = 8;
    $perChild = 25;
    $pids = [];

    for ($i = 0; $i < $children; $i++) {
        $pid = pcntl_fork();

        if ($pid === -1) {
            $this->markTestSkipped('Unable to fork a worker process.');
        }

        if ($pid === 0) {
            // Child: a fresh instance so flock handles are not shared with the parent.
            $child = new Cacheer(['cacheDir' => $dir]);
            for ($j = 0; $j < $perChild; $j++) {
                $child->increment('counter', 1);
            }
            exit(0);
        }

        $pids[] = $pid;
    }

    foreach ($pids as $pid) {
        pcntl_waitpid($pid, $status);
    }

    $final = (int) (new Cacheer(['cacheDir' => $dir]))->getCache('counter');

    // Without locking this would be < 100 because of lost updates.
    expect($final)->toBe($children * $perChild);
});

/**
 * Build a Cacheer configured for the given driver.
 */
function atomic_cache(string $driver): Cacheer
{
    if ($driver === 'file') {
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cacheer-incr-' . bin2hex(random_bytes(4));
        @mkdir($dir, 0775, true);
        return new Cacheer(['cacheDir' => $dir]);
    }

    if ($driver === 'database') {
        $cache = new Cacheer();
        $cache->setConfig()->setDatabaseConnection(Connect::getInstance()->getAttribute(PDO::ATTR_DRIVER_NAME));
        $cache->setDriver()->useDatabaseDriver();
        return $cache;
    }

    $cache = new Cacheer();
    $cache->setDriver()->useArrayDriver();
    return $cache;
}

/**
 * Recursively remove a directory.
 */
function atomic_rrmdir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    foreach (scandir($dir) ?: [] as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        is_dir($path) ? atomic_rrmdir($path) : @unlink($path);
    }
    @rmdir($dir);
}
