<?php

use Silviooosilva\CacheerPhp\Cacheer;
use Silviooosilva\CacheerPhp\Core\Connect;
use Silviooosilva\CacheerPhp\Support\CacheLock;

/*
|--------------------------------------------------------------------------
| Distributed lock conformance (Cacheer::lock())
|--------------------------------------------------------------------------
|
| The same behaviour is asserted across every driver via the "lock drivers"
| dataset, plus a file-specific test proving real flock exclusion across two
| separate store instances, and the static-facade path.
|
*/

dataset('lock drivers', ['array', 'file', 'database']);

beforeEach(function () {
    // Unique lock name per test, so the persistent DB locks table never
    // collides across tests or runs.
    lock_set_name('lock_' . bin2hex(random_bytes(6)));
});

afterEach(function () {
    Cacheer::resetInstance();

    foreach (glob(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cacheer-lock-*') ?: [] as $dir) {
        lock_rrmdir($dir);
    }

    // The DB lock provider uses a persistent table; keep it from accumulating.
    try {
        $pdo = Connect::getInstance();
        if ($pdo instanceof PDO) {
            $pdo->exec('DROP TABLE IF EXISTS cacheer_locks');
        }
    } catch (\Throwable) {
        // No DB configured for this test — nothing to clean.
    }
});

it('blocks a second holder until the first releases', function (string $driver) {
    $cache = lock_cache($driver);
    $a = $cache->lock(lock_name(), 30);
    $b = $cache->lock(lock_name(), 30);

    expect($a->acquire())->toBeTrue()      // first acquire succeeds
        ->and($b->acquire())->toBeFalse()  // second fails while held
        ->and($a->release())->toBeTrue()   // owner releases
        ->and($b->acquire())->toBeTrue();  // free again

    $b->release();
})->with('lock drivers');

it('only lets the owner release the lock', function (string $driver) {
    $cache = lock_cache($driver);
    $a = $cache->lock(lock_name(), 30);
    $b = $cache->lock(lock_name(), 30);

    expect($a->acquire())->toBeTrue()
        ->and($b->release())->toBeFalse()  // a non-owner must not release
        ->and($a->release())->toBeTrue();
})->with('lock drivers');

it('runs a callback under the lock and releases afterwards', function (string $driver) {
    $cache = lock_cache($driver);
    $ran = false;

    $result = $cache->lock(lock_name(), 30)->get(function () use (&$ran) {
        $ran = true;
        return 'done';
    });

    expect($ran)->toBeTrue()
        ->and($result)->toBe('done')
        ->and($cache->lock(lock_name(), 30)->acquire())->toBeTrue(); // released after get()
})->with('lock drivers');

it('returns false and skips the callback when the lock is held', function (string $driver) {
    $cache = lock_cache($driver);
    $holder = $cache->lock(lock_name(), 30);
    expect($holder->acquire())->toBeTrue();

    $ran = false;
    $result = $cache->lock(lock_name(), 30)->get(function () use (&$ran) {
        $ran = true;
        return 'should-not-run';
    });

    expect($result)->toBeFalse()
        ->and($ran)->toBeFalse();

    $holder->release();
})->with('lock drivers');

it('blocks roughly for the full timeout when the lock is held', function (string $driver) {
    $cache = lock_cache($driver);
    $holder = $cache->lock(lock_name(), 30);
    expect($holder->acquire())->toBeTrue();

    $start = microtime(true);
    $acquired = $cache->lock(lock_name(), 30)->block(1);
    $elapsed = microtime(true) - $start;

    expect($acquired)->toBeFalse()
        ->and($elapsed)->toBeGreaterThanOrEqual(0.9);

    $holder->release();
})->with('lock drivers');

it('excludes a second file-store instance via flock', function () {
    $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cacheer-lock-' . bin2hex(random_bytes(4));
    @mkdir($dir, 0775, true);

    $cacheA = new Cacheer(['cacheDir' => $dir]);
    $cacheB = new Cacheer(['cacheDir' => $dir]); // separate store, same directory

    $lockA = $cacheA->lock(lock_name(), 30);
    $lockB = $cacheB->lock(lock_name(), 30);

    expect($lockA->acquire())->toBeTrue()
        ->and($lockB->acquire())->toBeFalse()  // flock excludes the second instance
        ->and($lockA->release())->toBeTrue()
        ->and($lockB->acquire())->toBeTrue();   // free once the first releases

    $lockB->release();
});

it('exposes lock() on an instance returning a CacheLock', function () {
    expect(lock_cache('array')->lock(lock_name()))->toBeInstanceOf(CacheLock::class);
});

it('exposes lock() through the static facade', function () {
    Cacheer::setInstance(lock_cache('array'));

    $lock = Cacheer::lock(lock_name(), 30);

    expect($lock)->toBeInstanceOf(CacheLock::class)
        ->and($lock->acquire())->toBeTrue()
        ->and($lock->release())->toBeTrue();
});

/**
 * Build a Cacheer configured for the given driver.
 */
function lock_cache(string $driver): Cacheer
{
    if ($driver === 'file') {
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cacheer-lock-' . bin2hex(random_bytes(4));
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
function lock_rrmdir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    foreach (scandir($dir) ?: [] as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        is_dir($path) ? lock_rrmdir($path) : @unlink($path);
    }
    @rmdir($dir);
}

/**
 * Store current test lock name.
 */
function lock_set_name(string $name): void
{
    $GLOBALS['__cacheer_test_lock_name'] = $name;
}

/**
 * Read current test lock name.
 */
function lock_name(): string
{
    return $GLOBALS['__cacheer_test_lock_name'] ?? 'lock_' . bin2hex(random_bytes(6));
}
