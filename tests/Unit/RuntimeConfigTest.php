<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Silviooosilva\CacheerPhp\Boot\RuntimeConfig;
use Silviooosilva\CacheerPhp\Enums\DatabaseDriver;

class RuntimeConfigTest extends TestCase
{
    /**
     * @var array<string, string|false>
     */
    private array $environment = [];

    /**
     * @var array<string, mixed>
     */
    private array $envValues = [];

    /**
     * @var array<string, mixed>
     */
    private array $serverValues = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach ($this->keys() as $key) {
            $this->environment[$key] = getenv($key);
            if (array_key_exists($key, $_ENV)) {
                $this->envValues[$key] = $_ENV[$key];
            }
            if (array_key_exists($key, $_SERVER)) {
                $this->serverValues[$key] = $_SERVER[$key];
            }
        }

        RuntimeConfig::reset();
    }

    protected function tearDown(): void
    {
        foreach ($this->keys() as $key) {
            $value = $this->environment[$key];
            $value === false ? putenv($key) : putenv($key . '=' . $value);

            if (array_key_exists($key, $this->envValues)) {
                $_ENV[$key] = $this->envValues[$key];
            } else {
                unset($_ENV[$key]);
            }

            if (array_key_exists($key, $this->serverValues)) {
                $_SERVER[$key] = $this->serverValues[$key];
            } else {
                unset($_SERVER[$key]);
            }
        }

        RuntimeConfig::reset();

        parent::tearDown();
    }

    public function test_redis_configuration_is_resolved_lazily_from_environment(): void
    {
        $this->setEnvironment('REDIS_HOST', 'redis.internal');
        $this->setEnvironment('REDIS_PORT', '6380');
        $this->setEnvironment('REDIS_PASSWORD', 'secret');
        $this->setEnvironment('REDIS_NAMESPACE', 'tests:');
        $this->setEnvironment('REDIS_DB', '7');

        $this->assertSame([
            'client'    => '',
            'host'      => 'redis.internal',
            'port'      => 6380,
            'password'  => 'secret',
            'namespace' => 'tests:',
            'database'  => 7,
        ], RuntimeConfig::redis());
    }

    public function test_reset_discards_memoized_configuration(): void
    {
        $this->setEnvironment('REDIS_HOST', 'first.internal');
        $this->assertSame('first.internal', RuntimeConfig::redis()['host']);

        $this->setEnvironment('REDIS_HOST', 'second.internal');
        $this->assertSame('first.internal', RuntimeConfig::redis()['host']);

        RuntimeConfig::reset();

        $this->assertSame('second.internal', RuntimeConfig::redis()['host']);
    }

    public function test_sqlite_configuration_is_built_only_when_requested(): void
    {
        $configuration = RuntimeConfig::database(DatabaseDriver::SQLITE);

        $this->assertSame('sqlite', $configuration['adapter']);
        $this->assertSame('sqlite', $configuration['driver']);
        $this->assertArrayHasKey('dbname', $configuration);
        $this->assertArrayHasKey('options', $configuration);
    }

    /**
     * @return list<string>
     */
    private function keys(): array
    {
        return [
            'REDIS_CLIENT',
            'REDIS_HOST',
            'REDIS_PORT',
            'REDIS_PASSWORD',
            'REDIS_NAMESPACE',
            'REDIS_DB',
        ];
    }

    private function setEnvironment(string $key, string $value): void
    {
        putenv($key . '=' . $value);
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }
}
