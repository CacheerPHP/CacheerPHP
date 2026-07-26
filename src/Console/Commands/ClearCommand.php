<?php

declare(strict_types=1);

namespace Silviooosilva\CacheerPhp\Console\Commands;

use Silviooosilva\CacheerPhp\Console\CacheerContext;
use Silviooosilva\CacheerPhp\Console\Command;
use Silviooosilva\CacheerPhp\Console\CommandInput;
use Silviooosilva\CacheerPhp\Console\CommandOutput;

/**
 * Clear the entire configured keyspace. Names its target explicitly and refuses
 * to run without --force (or previews with --dry-run), so it can never wipe the
 * wrong store by accident.
 */
final class ClearCommand implements Command
{
    public function name(): string
    {
        return 'clear';
    }

    public function description(): string
    {
        return 'Clear the whole keyspace (--dry-run to preview, --force to execute).';
    }

    public function run(CommandInput $input, CommandOutput $output, ?CacheerContext $context): int
    {
        if ($context === null) {
            $output->line('No cacheer.config.php found.')->set('error', 'no_config');

            return 1;
        }

        $output->set('keyspace', $context->keyspace());

        if ($input->flag('dry-run')) {
            $output->set('dry_run', true)->set('cleared', false);
            $output->line(sprintf('[dry-run] would clear ALL entries in %s (not executed).', $context->keyspace()));

            return 0;
        }

        if (!$input->flag('force')) {
            $output->set('cleared', false)->set('error', 'force_required');
            $output->line(sprintf('Refusing to clear %s without --force.', $context->keyspace()));

            return 1;
        }

        $context->store()->clear();
        $output->set('dry_run', false)->set('cleared', true);
        $output->line(sprintf('Cleared ALL entries in %s.', $context->keyspace()));

        return 0;
    }
}
