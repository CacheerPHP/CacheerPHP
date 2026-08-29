<?php

declare(strict_types=1);

namespace Silviooosilva\CacheerPhp\Console\Commands;

use Silviooosilva\CacheerPhp\Console\CacheerContext;
use Silviooosilva\CacheerPhp\Console\Command;
use Silviooosilva\CacheerPhp\Console\CommandInput;
use Silviooosilva\CacheerPhp\Console\CommandOutput;
use Silviooosilva\CacheerPhp\Kernel\Key;
use Silviooosilva\CacheerPhp\Support\SystemClock;

/**
 * Inspect a single key's metadata (hit/miss, timestamps, remaining TTL) without
 * ever printing its value.
 */
final class InspectCommand implements Command
{
    /**
     * @return string
     */
    public function name(): string
    {
        return 'inspect';
    }

    /**
     * @return string
     */
    public function description(): string
    {
        return 'Show metadata for a key: cacheer inspect <key>';
    }

    /**
     * @param CommandInput $input
     * @param CommandOutput $output
     * @param ?CacheerContext $context
     * @return int
     */
    public function run(CommandInput $input, CommandOutput $output, ?CacheerContext $context): int
    {
        if ($context === null) {
            $output->line('No cacheer.config.php found.')->set('error', 'no_config');

            return 1;
        }

        $name = $input->argument(0);
        if ($name === null) {
            $output->line('Usage: cacheer inspect <key>')->set('error', 'missing_key');

            return 1;
        }

        $entry = $context->store()->get(Key::named($name));
        $clock = new SystemClock();

        $output
            ->set('key', $name)
            ->set('hit', $entry->isHit())
            ->set('created_at', $entry->createdAt())
            ->set('expires_at', $entry->expiresAt())
            ->set('remaining_ttl', $entry->isHit() ? $entry->remainingTtl($clock) : null);

        $output->line('key:           ' . $name);
        $output->line('hit:           ' . ($entry->isHit() ? 'yes' : 'no'));
        if ($entry->isHit()) {
            $output->line('created_at:    ' . ($entry->createdAt() ?? 'n/a'));
            $output->line('expires_at:    ' . ($entry->expiresAt() ?? 'never'));
            $output->line('remaining_ttl: ' . ($entry->remainingTtl($clock) ?? 'never'));
        }

        return $entry->isHit() ? 0 : 1;
    }
}
