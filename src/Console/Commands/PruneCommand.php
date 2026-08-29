<?php

declare(strict_types=1);

namespace Silviooosilva\CacheerPhp\Console\Commands;

use Silviooosilva\CacheerPhp\Console\CacheerContext;
use Silviooosilva\CacheerPhp\Console\Command;
use Silviooosilva\CacheerPhp\Console\CommandInput;
use Silviooosilva\CacheerPhp\Console\CommandOutput;
use Silviooosilva\CacheerPhp\Contracts\PrunableStore;

/**
 * Remove expired entries from the configured store. --dry-run reports the
 * target without deleting anything.
 */
final class PruneCommand implements Command
{
    /**
     * @return string
     */
    public function name(): string
    {
        return 'prune';
    }

    /**
     * @return string
     */
    public function description(): string
    {
        return 'Remove expired entries (--dry-run to preview).';
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

        $store = $context->store();
        if (!$store instanceof PrunableStore) {
            $output->line(sprintf('Store %s does not support pruning.', $context->keyspace()))->set('error', 'unsupported');

            return 1;
        }

        $output->set('keyspace', $context->keyspace());

        if ($input->flag('dry-run')) {
            $output->set('dry_run', true)->set('pruned', null);
            $output->line(sprintf('[dry-run] would prune expired entries in %s (not executed).', $context->keyspace()));

            return 0;
        }

        $removed = $store->prune();
        $output->set('dry_run', false)->set('pruned', $removed);
        $output->line(sprintf('Pruned %d expired entr%s from %s.', $removed, $removed === 1 ? 'y' : 'ies', $context->keyspace()));

        return 0;
    }
}
