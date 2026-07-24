<?php

declare(strict_types=1);

namespace Tests\Contract;

use PHPUnit\Framework\TestCase;

final class V5CharacterizationFixtureTest extends TestCase
{
    public function testFixtureCatalogsEverySupportedDriverAndCapability(): void
    {
        $fixture = require dirname(__DIR__) . '/Fixtures/V5/characterization.php';

        self::assertSame(
            ['array', 'file', 'redis', 'database'],
            array_keys($fixture['drivers']),
        );

        foreach ($fixture['drivers'] as $driver => $capabilities) {
            self::assertSame(
                ['base', 'batch', 'tags', 'locks', 'atomic', 'touch', 'inspection'],
                $capabilities,
                sprintf('Unexpected capability characterization for %s.', $driver),
            );
            self::assertSame(['prune'], $fixture['unsupported_capabilities'][$driver]);
        }
    }

    public function testFixtureCatalogsTheDocumentedPublicOperations(): void
    {
        $fixture = require dirname(__DIR__) . '/Fixtures/V5/characterization.php';
        $required = [
            'putCache', 'getCache', 'clearCache', 'flushCache',
            'has', 'missing', 'getMany', 'getAll', 'putMany', 'renewCache',
            'add', 'appendCache', 'forever', 'pull', 'getAndForget',
            'increment', 'decrement', 'lock', 'remember', 'rememberForever',
            'flexible', 'tag', 'flushTag',
        ];

        self::assertSame([], array_values(array_diff($required, $fixture['operations'])));
    }
}
