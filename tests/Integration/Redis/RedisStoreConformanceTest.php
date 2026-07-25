<?php

declare(strict_types=1);

namespace Tests\Integration\Redis;

use Predis\Client;
use Silviooosilva\CacheerPhp\Contracts\Store;
use Silviooosilva\CacheerPhp\Stores\RedisStore;
use Silviooosilva\CacheerPhp\Stores\Support\PredisConnection;
use Tests\Support\FakeClock;
use Tests\Support\StoreConformance;

final class RedisStoreConformanceTest extends StoreConformance
{
    private Client $client;

    private string $prefix;

    protected function createStore(FakeClock $clock): Store
    {
        $host = getenv('REDIS_HOST') ?: '127.0.0.1';
        $port = (int) (getenv('REDIS_PORT') ?: 6379);

        try {
            $this->client = new Client(['host' => $host, 'port' => $port]);
            $this->client->ping();
        } catch (\Throwable $exception) {
            self::markTestSkipped('Redis is not available: ' . $exception->getMessage());
        }

        $this->prefix = 'cacheer-test:' . bin2hex(random_bytes(4));

        return new RedisStore(new PredisConnection($this->client), $this->prefix, clock: $clock);
    }

    protected function tearDown(): void
    {
        if (isset($this->client)) {
            $keys = $this->client->keys($this->prefix . ':*');
            if ($keys !== []) {
                $this->client->del($keys);
            }
        }

        parent::tearDown();
    }
}
