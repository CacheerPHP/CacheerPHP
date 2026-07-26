<?php

declare(strict_types=1);

/**
 * Migrating from v5: the LegacyCacheer bridge exposes the old method surface on
 * top of the v6 engine, so you can adopt v6 without rewriting every call site at
 * once. Turn on deprecations in development to find the call sites to migrate.
 *
 * Run: php examples/v6/03-legacy-bridge.php
 */

require __DIR__ . '/../../vendor/autoload.php';

use Silviooosilva\CacheerPhp\Compat\LegacyCacheer;

$legacy = LegacyCacheer::inMemory();

// The familiar v5 API keeps working.
$legacy->putCache('user:1', ['name' => 'Ada'], 'accounts', 3600);
assert($legacy->getCache('user:1', 'accounts') === ['name' => 'Ada']);
assert($legacy->isSuccess() === true);

assert($legacy->increment('visits') === 1);
assert($legacy->increment('visits', 4) === 5);

$legacy->clearCache('user:1', 'accounts');
assert($legacy->getCache('user:1', 'accounts') === null);
assert($legacy->isSuccess() === false);

echo "OK\n";
