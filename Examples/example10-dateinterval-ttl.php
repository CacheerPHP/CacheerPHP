<?php

/**
 * Example 10 — DateInterval and null TTL (v5.0.0)
 *
 * TTLs are no longer limited to integers or human-readable strings.
 * putCache(), add(), remember(), and renewCache() now accept:
 *   - int           → seconds
 *   - string        → "1 hour", "30 minutes", etc.
 *   - \DateInterval → converted to seconds automatically
 *   - null          → store forever (PHP_INT_MAX)
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Silviooosilva\CacheerPhp\Cacheer;

$Cacheer = new Cacheer();
$Cacheer->setDriver()->useArrayDriver();

// ── 1. int TTL (unchanged from v4) ────────────────────────────────────────────

$Cacheer->putCache('key_int', 'integer TTL', ttl: 3600);
echo $Cacheer->getCache('key_int') . PHP_EOL;          // integer TTL

// ── 2. string TTL (unchanged from v4) ─────────────────────────────────────────

$Cacheer->putCache('key_string', 'string TTL', ttl: '2 hours');
echo $Cacheer->getCache('key_string') . PHP_EOL;       // string TTL

// ── 3. DateInterval TTL (new in v5.0.0) ───────────────────────────────────────

$Cacheer->putCache('key_interval', 'DateInterval TTL', ttl: new DateInterval('PT45M')); // 45 min
echo $Cacheer->getCache('key_interval') . PHP_EOL;     // DateInterval TTL

// ── 4. null TTL — store forever (new in v5.0.0) ───────────────────────────────

$Cacheer->putCache('key_forever', 'lives forever', ttl: null);
echo $Cacheer->getCache('key_forever') . PHP_EOL;      // lives forever

// ── 5. remember() with DateInterval ───────────────────────────────────────────

$result = $Cacheer->remember('computed', new DateInterval('P1D'), function () {
    // Expensive computation, called only once.
    return 'computed value — ' . date('Y-m-d');
});
echo $result . PHP_EOL;

// Second call returns the cached value; the closure is NOT called again.
$result2 = $Cacheer->remember('computed', new DateInterval('P1D'), function () {
    return 'this will NOT be returned';
});
echo $result2 . PHP_EOL;  // same as $result

// ── 6. renewCache() with DateInterval ─────────────────────────────────────────

$Cacheer->putCache('renewable', 'data', ttl: 60);
$Cacheer->renewCache('renewable', new DateInterval('PT2H'));  // extend to 2 hours

echo $Cacheer->isSuccess()
    ? 'Cache renewed: ' . $Cacheer->getMessage()
    : 'Renew failed: ' . $Cacheer->getMessage();
echo PHP_EOL;
