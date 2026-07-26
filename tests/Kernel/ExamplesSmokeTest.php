<?php

declare(strict_types=1);

namespace Tests\Kernel;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Runs the documented v6 examples as real processes with assertions enabled, so
 * the snippets shipped in the docs cannot silently rot. Each example asserts its
 * own behavior and prints "OK" on success.
 */
final class ExamplesSmokeTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function examples(): array
    {
        $dir = dirname(__DIR__, 2) . '/examples/v6';
        $cases = [];

        foreach (glob($dir . '/*.php') ?: [] as $file) {
            $cases[basename($file)] = [$file];
        }

        return $cases;
    }

    #[DataProvider('examples')]
    public function testExampleRunsCleanly(string $file): void
    {
        $command = sprintf(
            '%s -d zend.assertions=1 -d assert.exception=1 %s 2>&1',
            escapeshellarg(PHP_BINARY),
            escapeshellarg($file),
        );

        $output = [];
        $exitCode = 0;
        exec($command, $output, $exitCode);
        $text = implode("\n", $output);

        self::assertSame(0, $exitCode, sprintf("Example %s failed:\n%s", basename($file), $text));
        self::assertStringContainsString('OK', $text);
    }
}
