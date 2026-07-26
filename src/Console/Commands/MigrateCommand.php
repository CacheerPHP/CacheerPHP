<?php

declare(strict_types=1);

namespace Silviooosilva\CacheerPhp\Console\Commands;

use Silviooosilva\CacheerPhp\Console\CacheerContext;
use Silviooosilva\CacheerPhp\Console\Command;
use Silviooosilva\CacheerPhp\Console\CommandInput;
use Silviooosilva\CacheerPhp\Console\CommandOutput;
use Silviooosilva\CacheerPhp\Stores\Support\DatabaseStoreSchema;

/**
 * Create the database store schema. Requires a PDO connection in the context;
 * --dry-run prints the exact DDL instead of executing it.
 */
final class MigrateCommand implements Command
{
    public function name(): string
    {
        return 'migrate';
    }

    public function description(): string
    {
        return 'Create the database store schema (--dry-run to print the DDL).';
    }

    public function run(CommandInput $input, CommandOutput $output, ?CacheerContext $context): int
    {
        if ($context === null || $context->pdo() === null) {
            $output->line('migrate requires a PDO connection in cacheer.config.php.')->set('error', 'no_pdo');

            return 1;
        }

        $pdo = $context->pdo();
        $table = $context->table();
        $statements = DatabaseStoreSchema::sqlFor($pdo, $table);
        $output->set('table', $table)->set('statements', $statements);

        if ($input->flag('dry-run')) {
            $output->set('dry_run', true)->set('migrated', false);
            foreach ($statements as $statement) {
                $output->line(rtrim($statement, "\n") . ';');
            }

            return 0;
        }

        DatabaseStoreSchema::migrate($pdo, $table);
        $output->set('dry_run', false)->set('migrated', true);
        $output->line(sprintf('Migrated schema for table "%s" (%d statements).', $table, count($statements)));

        return 0;
    }
}
