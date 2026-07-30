<?php

declare(strict_types=1);

namespace Tests\Storage;

use PHPUnit\Framework\TestCase;
use Silviooosilva\CacheerPhp\Cacheer;
use Silviooosilva\CacheerPhp\Config\PipelineConfig;

/**
 * Guards roadmap principle #5 for the v6 layer: the kernel, stores, storage
 * pipeline, and typed config must not load .env files, read process
 * environment, or change the global timezone merely by being used.
 */
final class NoHiddenSideEffectsTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function v6SourcePaths(): array
    {
        $root = dirname(__DIR__, 2) . '/src';

        return [
            'Kernel'  => [$root . '/Kernel'],
            'Storage' => [$root . '/Storage'],
            'Stores'  => [$root . '/Stores'],
            'Core'    => [$root . '/Core/CacheOperations.php'],
            'Config'  => [$root . '/Config/PipelineConfig.php'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('v6SourcePaths')]
    public function testV6LayerContainsNoEnvironmentOrTimezoneSideEffects(string $path): void
    {
        $forbidden = ['Dotenv', 'getenv(', 'putenv(', '$_ENV', '$_SERVER', 'date_default_timezone_set'];

        foreach ($this->phpFiles($path) as $file) {
            $source = (string) file_get_contents($file);

            foreach ($forbidden as $token) {
                self::assertStringNotContainsString(
                    $token,
                    $source,
                    sprintf('%s must not reference "%s".', basename($file), $token),
                );
            }
        }
    }

    public function testUsingTheV6PipelineDoesNotChangeTheGlobalTimezone(): void
    {
        $before = date_default_timezone_get();

        $codec = PipelineConfig::default()->withGzip()->codec();
        $codec->decode($codec->encode(['x' => 1]));

        $cache = Cacheer::inMemory();
        $cache->set('k', 'v');

        self::assertSame('v', $cache->get('k'));
        self::assertSame($before, date_default_timezone_get());
    }

    /**
     * @return list<string>
     */
    private function phpFiles(string $path): array
    {
        if (is_file($path)) {
            return [$path];
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $item) {
            if ($item->isFile() && $item->getExtension() === 'php') {
                $files[] = $item->getPathname();
            }
        }

        return $files;
    }
}
