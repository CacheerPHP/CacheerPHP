<?php

namespace Tests\Integration\Redis;

use PHPUnit\Framework\TestCase;
use Predis\Client;
use Silviooosilva\CacheerPhp\Boot\RuntimeConfig;
use Throwable;

abstract class RedisIntegrationTestCase extends TestCase
{
    protected ?Client $redisClient = null;

    protected function setUp(): void
    {
        parent::setUp();

        $database = $this->resolveDatabase();
        $this->setEnvironment('REDIS_DB', (string) $database);
        RuntimeConfig::reset();

        $config = RuntimeConfig::redis();

        $parameters = [
            'scheme'   => 'tcp',
            'host'     => $config['host'],
            'port'     => $config['port'],
            'database' => $database,
        ];
        if ($config['password'] !== '') {
            $parameters['password'] = $config['password'];
        }

        try {
            $this->redisClient = new Client($parameters);
            $this->redisClient->connect();
            $this->redisClient->ping();
            $this->redisClient->flushdb();
        } catch (Throwable $exception) {
            $this->redisClient?->disconnect();
            $this->redisClient = null;

            $message = 'Redis integration service is unavailable: ' . $exception->getMessage();
            if (getenv('CACHEER_REQUIRE_REDIS') === '1') {
                self::fail($message);
            }

            self::markTestSkipped($message);
        }
    }

    protected function tearDown(): void
    {
        if ($this->redisClient instanceof Client) {
            try {
                $this->redisClient->flushdb();
            } finally {
                $this->redisClient->disconnect();
            }
        }

        $this->redisClient = null;
        RuntimeConfig::reset();

        parent::tearDown();
    }

    private function resolveDatabase(): int
    {
        $token = getenv('TEST_TOKEN');
        if (is_string($token) && $token !== '') {
            return abs(crc32($token)) % 16;
        }

        $configured = getenv('REDIS_DB');

        return $configured === false ? 0 : max(0, (int) $configured);
    }

    private function setEnvironment(string $key, string $value): void
    {
        putenv($key . '=' . $value);
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }
}
