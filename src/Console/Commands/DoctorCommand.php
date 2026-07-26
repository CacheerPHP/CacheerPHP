<?php

declare(strict_types=1);

namespace Silviooosilva\CacheerPhp\Console\Commands;

use Silviooosilva\CacheerPhp\Console\CacheerContext;
use Silviooosilva\CacheerPhp\Console\Command;
use Silviooosilva\CacheerPhp\Console\CommandInput;
use Silviooosilva\CacheerPhp\Console\CommandOutput;

/**
 * Environment and configuration diagnostics for health checks and CI.
 */
final class DoctorCommand implements Command
{
    public function name(): string
    {
        return 'doctor';
    }

    public function description(): string
    {
        return 'Check the PHP runtime, optional extensions, and cache configuration.';
    }

    public function run(CommandInput $input, CommandOutput $output, ?CacheerContext $context): int
    {
        $checks = [];
        $checks[] = $this->check('php', version_compare(PHP_VERSION, '8.3.0', '>='), PHP_VERSION, true);

        foreach (['pdo', 'openssl', 'zlib', 'redis'] as $extension) {
            $loaded = extension_loaded($extension);
            $checks[] = $this->check("ext-{$extension}", $loaded, $loaded ? 'loaded' : 'not loaded (optional)');
        }

        $checks[] = $this->check(
            'config',
            $context !== null,
            $context !== null ? 'store: ' . $context->keyspace() : 'no cacheer.config.php found',
        );

        $healthy = true;
        foreach ($checks as $check) {
            $output->line(sprintf('[%s] %-12s %s', $check['ok'] ? 'OK' : '!!', $check['name'], $check['detail']));
            if ($check['critical'] && !$check['ok']) {
                $healthy = false;
            }
        }

        $output->set('checks', $checks)->set('healthy', $healthy);

        return $healthy ? 0 : 1;
    }

    /**
     * @return array{name: string, ok: bool, detail: string, critical: bool}
     */
    private function check(string $name, bool $ok, string $detail, bool $critical = false): array
    {
        return ['name' => $name, 'ok' => $ok, 'detail' => $detail, 'critical' => $critical];
    }
}
