<?php

declare(strict_types=1);

use Silviooosilva\CacheerPhp\Cacheer;
use Tests\Support\ConcurrencyHarness;

afterEach(function (): void {
    foreach (glob(sys_get_temp_dir() . '/cacheer-invalidation-*') ?: [] as $directory) {
        invalidation_remove_directory($directory);
    }
});

it('does not expose a file value to readers released after invalidation', function (): void {
    $harness = new ConcurrencyHarness();
    if (!$harness->isAvailable()) {
        $this->markTestSkipped('The pcntl extension is required for invalidation concurrency tests.');
    }

    $directory = sys_get_temp_dir() . '/cacheer-invalidation-' . bin2hex(random_bytes(4));
    mkdir($directory, 0775, true);
    $barrier = $directory . '/readers-go';

    $cache = new Cacheer(['cacheDir' => $directory]);
    $cache->putCache('shared', 'stale');

    $pid = pcntl_fork();
    if ($pid === -1) {
        $this->markTestSkipped('Unable to fork the invalidation coordinator.');
    }

    if ($pid === 0) {
        $childHarness = new ConcurrencyHarness();
        $codes = $childHarness->run(6, function () use ($directory, $barrier, $childHarness): bool {
            if (!$childHarness->await($barrier)) {
                return false;
            }

            $reader = new Cacheer(['cacheDir' => $directory]);

            return $reader->getCache('shared') === null && !$reader->isSuccess();
        });
        exit($codes === array_fill(0, 6, 0) ? 0 : 1);
    }

    $cache->clearCache('shared');
    $harness->release($barrier);
    pcntl_waitpid($pid, $status);

    expect(pcntl_wifexited($status))->toBeTrue()
        ->and(pcntl_wexitstatus($status))->toBe(0);
});

function invalidation_remove_directory(string $directory): void
{
    if (!is_dir($directory)) {
        return;
    }

    foreach (scandir($directory) ?: [] as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        $path = $directory . DIRECTORY_SEPARATOR . $item;
        is_dir($path) ? invalidation_remove_directory($path) : @unlink($path);
    }

    @rmdir($directory);
}
