<?php

declare(strict_types=1);

/**
 * CacheerPHP v5 -> v6 migration rule set.
 *
 * This is an optional, opt-in Rector configuration. It is NOT part of the
 * package runtime and has no effect unless you install Rector as a dev
 * dependency and run it yourself:
 *
 *     composer require rector/rector --dev
 *     vendor/bin/rector process src --config rector.php --dry-run
 *
 * What it does automatically:
 *   - Renames the v5 method surface to the v6 kernel names on any object typed
 *     as Cacheer or LegacyCacheer (putCache -> set, getCache -> get, and so on).
 *
 * What it deliberately does NOT do (review these by hand — see MIGRATION.md):
 *   - Change construction: `(new Cacheer(...))->setDriver()->useFileDriver()`
 *     becomes `Cache::file($dir)`. Driver selection is not a mechanical rename.
 *   - Move the positional namespace argument onto a `->scope()` call.
 *   - Drop the read-time TTL argument that v6's get() no longer accepts.
 *   - Translate `isSuccess()` into checking `entry()->isHit()` or a return value.
 *
 * Start on the LegacyCacheer bridge (a drop-in for the v5 API), let this rule
 * set flag the straightforward renames, then migrate call sites to the v6 Cache
 * instance API guided by the compatibility table in MIGRATION.md.
 */

use Rector\Config\RectorConfig;
use Rector\Renaming\Rector\MethodCall\RenameMethodRector;
use Rector\Renaming\ValueObject\MethodCallRename;

return static function (RectorConfig $rectorConfig): void {
    $rectorConfig->paths([__DIR__ . '/src']);

    $legacyClasses = [
        'Silviooosilva\\CacheerPhp\\Cacheer',
        'Silviooosilva\\CacheerPhp\\Compat\\LegacyCacheer',
    ];

    $renames = [
        'putCache'      => 'set',
        'getCache'      => 'get',
        'clearCache'    => 'delete',
        'forget'        => 'delete',
        'flushCache'    => 'clear',
        'getAndForget'  => 'pull',
        'renewCache'    => 'set',
        'putMany'       => 'setMany',
    ];

    $methodRenames = [];
    foreach ($legacyClasses as $class) {
        foreach ($renames as $from => $to) {
            $methodRenames[] = new MethodCallRename($class, $from, $to);
        }
    }

    $rectorConfig->ruleWithConfiguration(RenameMethodRector::class, $methodRenames);
};
