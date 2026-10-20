<?php

declare(strict_types=1);

namespace Evidence;

use Predis\Client;
use RuntimeException;

/**
 * A throwaway redis-server dedicated to one run, so results never depend on (or
 * touch) whatever else is on the machine's default Redis. Persistence is off;
 * the data lives only as long as the process.
 *
 * Outages are produced the way they happen in production:
 * - freeze(): the process stops responding (SIGSTOP) but keeps its data and
 *   accepts TCP connections, so clients block until their read timeout fires;
 * - kill(): the process is gone and connections are refused outright.
 */
final class RedisServer
{
    private ?int $pid = null;

    private readonly string $dir;

    public function __construct(public readonly int $port = 6391)
    {
        $this->dir = sys_get_temp_dir() . '/cacheer-evidence-redis-' . $port;
    }

    public function start(): void
    {
        if ($this->isAnswering()) {
            throw new RuntimeException(sprintf('Port %d is already serving Redis; pick another port.', $this->port));
        }

        @mkdir($this->dir, 0775, true);
        $pidFile = $this->dir . '/redis.pid';
        @unlink($pidFile);

        $command = sprintf(
            'redis-server --port %d --bind 127.0.0.1 --save "" --appendonly no --daemonize yes --pidfile %s --dir %s --logfile %s',
            $this->port,
            escapeshellarg($pidFile),
            escapeshellarg($this->dir),
            escapeshellarg($this->dir . '/redis.log'),
        );
        exec($command, $output, $status);

        if ($status !== 0) {
            throw new RuntimeException('redis-server could not be started; is it installed and on PATH?');
        }

        $this->waitUntil(fn (): bool => $this->isAnswering(), 'Redis did not start');
        $this->pid = (int) trim((string) @file_get_contents($pidFile));
    }

    public function freeze(): void
    {
        posix_kill($this->requirePid(), SIGSTOP);
    }

    public function thaw(): void
    {
        posix_kill($this->requirePid(), SIGCONT);
        $this->waitUntil(fn (): bool => $this->isAnswering(), 'Redis did not resume');
    }

    public function kill(): void
    {
        posix_kill($this->requirePid(), SIGKILL);
        $this->waitUntil(fn (): bool => !$this->isAnswering(), 'Redis did not stop');
        $this->pid = null;
    }

    public function stop(): void
    {
        if ($this->pid === null) {
            return;
        }

        posix_kill($this->pid, SIGCONT); // in case it was frozen
        posix_kill($this->pid, SIGTERM);
        $this->waitUntil(fn (): bool => !$this->isAnswering(), 'Redis did not stop');
        $this->pid = null;
    }

    public function version(): string
    {
        $info = $this->client()->info('server');

        return (string) ($info['Server']['redis_version'] ?? $info['redis_version'] ?? 'unknown');
    }

    /**
     * A client tuned for outage behavior: short connect and read timeouts, so a
     * frozen server costs a bounded delay instead of PHP's default minute.
     */
    public function client(float $timeout = 0.25): Client
    {
        return new Client(
            ['host' => '127.0.0.1', 'port' => $this->port, 'timeout' => $timeout, 'read_write_timeout' => $timeout],
        );
    }

    private function isAnswering(): bool
    {
        set_error_handler(static fn (): bool => true);

        try {
            return (string) $this->client(0.1)->ping() === 'PONG';
        } catch (\Throwable) {
            return false;
        } finally {
            restore_error_handler();
        }
    }

    private function requirePid(): int
    {
        return $this->pid ?? throw new RuntimeException('Redis is not running.');
    }

    private function waitUntil(callable $condition, string $failure): void
    {
        $deadline = microtime(true) + 5.0;

        while (!$condition()) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException($failure . '.');
            }

            usleep(20_000);
        }
    }
}
