<?php

declare(strict_types=1);

namespace Tests\Integration\Redis;

use Predis\Client;
use Silviooosilva\CacheerPhp\Boot\RuntimeConfig;
use Silviooosilva\CacheerPhp\Cacheer;
use Tests\Contract\StoreContractTestCase;
use Tests\Support\FakeClock;
use Throwable;

final class RedisStoreContractTest extends StoreContractTestCase
{
    protected function createCache(FakeClock $clock): Cacheer
    {
        RuntimeConfig::reset();
        $config = RuntimeConfig::redis();
        $parameters = [
            'scheme'   => 'tcp',
            'host'     => $config['host'],
            'port'     => $config['port'],
            'database' => $config['database'],
        ];

        if ($config['password'] !== '') {
            $parameters['password'] = $config['password'];
        }

        try {
            $client = new Client($parameters);
            $client->connect();
            $client->ping();
            $client->disconnect();

            $cache = new Cacheer(['clock' => $clock]);
            $cache->setDriver()->useRedisDriver();

            return $cache;
        } catch (Throwable $exception) {
            $message = 'Redis contract service is unavailable: ' . $exception->getMessage();
            if (getenv('CACHEER_REQUIRE_REDIS') === '1') {
                self::fail($message);
            }

            self::markTestSkipped($message);
        }
    }

    protected function advanceTime(float $seconds): void
    {
        usleep((int) ceil($seconds * 1_000_000));
    }

    protected function tearDown(): void
    {
        RuntimeConfig::reset();
        parent::tearDown();
    }
}
