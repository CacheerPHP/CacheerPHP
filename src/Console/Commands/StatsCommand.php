<?php

declare(strict_types=1);

namespace Silviooosilva\CacheerPhp\Console\Commands;

use Silviooosilva\CacheerPhp\Console\CacheerContext;
use Silviooosilva\CacheerPhp\Console\Command;
use Silviooosilva\CacheerPhp\Console\CommandInput;
use Silviooosilva\CacheerPhp\Console\CommandOutput;
use Silviooosilva\CacheerPhp\Contracts\AtomicStore;
use Silviooosilva\CacheerPhp\Contracts\BatchStore;
use Silviooosilva\CacheerPhp\Contracts\FlushableScopeStore;
use Silviooosilva\CacheerPhp\Contracts\InspectableStore;
use Silviooosilva\CacheerPhp\Contracts\LockingStore;
use Silviooosilva\CacheerPhp\Contracts\PrunableStore;
use Silviooosilva\CacheerPhp\Contracts\TaggableStore;
use Silviooosilva\CacheerPhp\Contracts\TouchStore;

/**
 * Report the configured store, the capabilities it actually implements, and a
 * live entry count when the store supports inspection.
 */
final class StatsCommand implements Command
{
    private const CAPABILITIES = [
        BatchStore::class          => 'batch',
        TaggableStore::class       => 'tags',
        LockingStore::class        => 'locks',
        AtomicStore::class         => 'atomic',
        TouchStore::class          => 'touch',
        PrunableStore::class       => 'prune',
        InspectableStore::class    => 'inspect',
        FlushableScopeStore::class => 'scope-flush',
    ];

    public function name(): string
    {
        return 'stats';
    }

    public function description(): string
    {
        return 'Show the configured store, its capabilities, and entry count.';
    }

    public function run(CommandInput $input, CommandOutput $output, ?CacheerContext $context): int
    {
        if ($context === null) {
            return $this->noConfig($output);
        }

        $store = $context->store();

        $capabilities = [];
        foreach (self::CAPABILITIES as $interface => $label) {
            if ($store instanceof $interface) {
                $capabilities[] = $label;
            }
        }

        $entries = null;
        if ($store instanceof InspectableStore) {
            $entries = iterator_count($this->iterate($store));
        }

        $output->set('store', $context->keyspace())->set('capabilities', $capabilities)->set('entries', $entries);
        $output->line('store:        ' . $context->keyspace());
        $output->line('capabilities: ' . implode(', ', $capabilities));
        $output->line('entries:      ' . ($entries ?? 'n/a'));

        return 0;
    }

    private function iterate(InspectableStore $store): \Generator
    {
        yield from $store->entries();
    }

    private function noConfig(CommandOutput $output): int
    {
        $output->line('No cacheer.config.php found.')->set('error', 'no_config');

        return 1;
    }
}
