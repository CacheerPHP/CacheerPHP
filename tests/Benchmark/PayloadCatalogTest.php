<?php

declare(strict_types=1);

namespace Tests\Benchmark;

use PHPUnit\Framework\TestCase;

final class PayloadCatalogTest extends TestCase
{
    public function testBaselineContainsEveryRequiredPayloadClass(): void
    {
        $payloads = require dirname(__DIR__, 2) . '/benchmarks/payloads.php';

        self::assertSame(
            ['scalar', 'array', 'object', '1_kb', '100_kb', '1_mb'],
            array_keys($payloads),
        );
        self::assertSame(1024, strlen($payloads['1_kb']));
        self::assertSame(100 * 1024, strlen($payloads['100_kb']));
        self::assertSame(1024 * 1024, strlen($payloads['1_mb']));
    }
}
