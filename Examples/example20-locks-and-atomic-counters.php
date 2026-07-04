<?php

/**
 * Example 20 — Distributed Locks & Atomic Counters
 *
 * Two concurrency-safe building blocks (new in v5.2.0):
 *
 *   - lock()       a named, driver-backed mutex so only one process runs a
 *                  critical section at a time.
 *   - increment()  / decrement() are now atomic — concurrent counter updates
 *                  no longer lose increments on lockable drivers.
 *
 * Both work across processes on the File, Database, and Redis drivers.
 *
 * When is a lock useful? Your app runs as many PHP processes at once (php-fpm
 * workers, queue workers, cron) across one or more servers. A lock makes sure
 * only ONE of them runs a block of code at a time. Real cases:
 *
 *   - a cron on 3 servers that must send the daily email only once;
 *   - not double-charging a payment on a double-click / retry;
 *   - only one worker draining a queue (a "singleton" job);
 *   - serialising calls to a fragile external API.
 *
 * Use it for "must happen once" side-effects. For plain counters use
 * increment() (already atomic), and for cache recomputation use remember() /
 * flexible() — they lock internally to prevent stampedes.
 */

require_once __DIR__ . "/../vendor/autoload.php";

use Silviooosilva\CacheerPhp\Cacheer;
use Silviooosilva\CacheerPhp\Config\Option\Builder\OptionBuilder;

$options = OptionBuilder::forFile()
    ->dir(__DIR__ . "/cache")
    ->build();

$Cacheer = new Cacheer($options);

// --- Run a callback under a lock; others get false instead of running it ----
$result = $Cacheer->lock("rebuild-report", 30)->get(function () {
    // Only one process at a time reaches this block.
    return "report rebuilt at " . date("H:i:s");
});
echo $result . PHP_EOL;                                   // "report rebuilt at ..."

// --- Manual acquire / release ----------------------------------------------
$lock = $Cacheer->lock("nightly-job", 120);
if ($lock->acquire()) {
    try {
        echo "Running the nightly job exclusively..." . PHP_EOL;
        // doWork();
    } finally {
        $lock->release();
    }
} else {
    echo "Another process is already running the job." . PHP_EOL;
}

// --- Wait up to 5s for a contended lock, then run --------------------------
$charged = $Cacheer->lock("invoice:42", 30)->block(5, function () {
    return "invoice 42 charged";
});
echo ($charged ?: "could not acquire the lock in time") . PHP_EOL;

// --- Atomic counters --------------------------------------------------------
$Cacheer->putCache("views:post:1", 0);

$Cacheer->increment("views:post:1");         // +1  → 1
$Cacheer->increment("views:post:1", 10);     // +10 → 11
$Cacheer->decrement("views:post:1", 3);      // -3  → 8

echo "views:post:1 = " . $Cacheer->getCache("views:post:1") . PHP_EOL;   // 8

// Under concurrency (multiple PHP processes hitting these lines at once),
// every increment is applied exactly once — no lost updates.
