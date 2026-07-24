<?php

use Silviooosilva\CacheerPhp\Helpers\EnvHelper;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Fresh per-worker local state
|--------------------------------------------------------------------------
|
| ParaTest workers reuse token-scoped SQLite files and default file-cache
| directories between runs. Clean only the current worker's resources before
| tests start. Service-backed resources are owned by their integration base
| classes and are never contacted from this service-free bootstrap.
|
*/

(static function (): void {
    $token = getenv('TEST_TOKEN');
    if ($token === false || $token === '') {
        return;
    }

    $safeToken = preg_replace('/[^A-Za-z0-9_]/', '', (string) $token);
    $root = EnvHelper::getRootPath();
    $database = $root . '/database/database.' . $safeToken . '.sqlite';

    if (is_file($database)) {
        @unlink($database);
    }

    $cacheDirectory = $root . '/CacheerPHP/Cache_' . $safeToken;
    if (!is_dir($cacheDirectory)) {
        return;
    }

    foreach (glob($cacheDirectory . '/*') ?: [] as $file) {
        if (is_file($file)) {
            @unlink($file);
        }
    }
})();

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}
