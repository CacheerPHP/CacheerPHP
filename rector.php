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
 *   - Renames the straightforward v5 methods to the v6 names on any object typed
 *     as Cacheer (putCache -> set, getCache -> get, clearCache -> delete,
 *     renewCache -> touch, getAndForget -> pull, and so on). Verbs v6 kept
 *     unchanged — add, forever, missing, pull, increment, decrement, tag,
 *     flushTag, lock, rememberForever, stats — need no rule at all.
 *
 * What it deliberately does NOT do (review these by hand — see MIGRATION.md):
 *   - Change construction: `(new Cacheer(...))->setDriver()->useFileDriver()`
 *     becomes `Cacheer::file($dir)`. Driver selection is not a mechanical rename.
 *   - Move the positional namespace argument onto a `->scope()` call.
 *   - Drop the read-time TTL argument that v6's get() no longer accepts.
 *   - Translate `isSuccess()` into checking `entry()->isHit()` or a return value.
 *   - Drop the trailing `$namespace` argument that several v5 methods took; the
 *     rename lands, but the extra argument must move onto `->scope()` by hand.
 *
 * Let this rule set flag the mechanical renames, then migrate the remaining call
 * sites to the v6 instance API guided by the compatibility table in MIGRATION.md.
 */

use Rector\Config\RectorConfig;
use Rector\Renaming\Rector\MethodCall\RenameMethodRector;
use Rector\Renaming\ValueObject\MethodCallRename;

return static function (RectorConfig $rectorConfig): void {
    $rectorConfig->paths([__DIR__ . '/src']);

    $cacheer = 'Silviooosilva\\CacheerPhp\\Cacheer';

    $renames = [
        'putCache'   => 'set',
        'getCache'   => 'get',
        'clearCache' => 'delete',
        'forget'     => 'delete',
        'flushCache' => 'clear',
        'renewCache' => 'touch',
        'putMany'    => 'setMany',
        'getMany'    => 'many',

        // Kept verbs whose v5 spelling differed.
        'getAndForget' => 'pull',
    ];

    $methodRenames = [];
    foreach ($renames as $from => $to) {
        $methodRenames[] = new MethodCallRename($cacheer, $from, $to);
    }

    $rectorConfig->ruleWithConfiguration(RenameMethodRector::class, $methodRenames);
};
