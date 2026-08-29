<?php

declare(strict_types=1);

namespace Silviooosilva\CacheerPhp\Console;

use PDO;
use Silviooosilva\CacheerPhp\Console\Commands\ClearCommand;
use Silviooosilva\CacheerPhp\Console\Commands\DoctorCommand;
use Silviooosilva\CacheerPhp\Console\Commands\InspectCommand;
use Silviooosilva\CacheerPhp\Console\Commands\MigrateCommand;
use Silviooosilva\CacheerPhp\Console\Commands\PruneCommand;
use Silviooosilva\CacheerPhp\Console\Commands\StatsCommand;
use Silviooosilva\CacheerPhp\Contracts\Store;

/**
 * The `cacheer` CLI: parses argv, resolves the application bootstrap, and
 * dispatches to a command. Commands and the bootstrap are both injectable, so
 * the whole thing is testable without a real shell.
 */
final class Application
{
    /**
     * @var array<string, Command>
     */
    private array $commands = [];

    public function __construct()
    {
        foreach ([
            new DoctorCommand(),
            new StatsCommand(),
            new InspectCommand(),
            new PruneCommand(),
            new ClearCommand(),
            new MigrateCommand(),
        ] as $command) {
            $this->add($command);
        }
    }

    /**
     * @param Command $command
     */
    public function add(Command $command): void
    {
        $this->commands[$command->name()] = $command;
    }

    /**
     * @param list<string> $argv Full argv (index 0 is the script name).
     * @param ?CacheerContext $context
     * @return int
     */
    public function run(array $argv, ?CacheerContext $context = null): int
    {
        $tokens = array_slice($argv, 1);
        $name = $tokens[0] ?? 'list';

        if ($name === 'list' || $name === 'help' || $name === '--help') {
            echo $this->listing();

            return 0;
        }

        $command = $this->commands[$name] ?? null;
        if ($command === null) {
            fwrite(STDERR, sprintf('Unknown command "%s". Run "cacheer list".%s', $name, PHP_EOL));

            return 1;
        }

        $input = CommandInput::fromTokens(array_slice($tokens, 1));
        $output = new CommandOutput($input->flag('json'));
        $context ??= $this->bootstrap($input);

        $code = $command->run($input, $output, $context);
        echo $output->render() . PHP_EOL;

        return $code;
    }

    /**
     * @return string
     */
    private function listing(): string
    {
        $lines = ['CacheerPHP CLI', '', 'Commands:'];
        foreach ($this->commands as $command) {
            $lines[] = sprintf('  %-10s %s', $command->name(), $command->description());
        }

        return implode(PHP_EOL, $lines) . PHP_EOL;
    }

    /**
     * Load the project's explicit bootstrap (default: cacheer.config.php in the
     * working directory, or --config=path). The file returns a Store, or an
     * array of ['store' => Store, 'pdo' => PDO, 'table' => string]. Returns null
     * when no config is present, so environment-only commands still work.
     *
     * @param CommandInput $input
     * @return ?CacheerContext
     */
    private function bootstrap(CommandInput $input): ?CacheerContext
    {
        $path = $input->option('config', getcwd() . '/cacheer.config.php');
        if ($path === null || !is_file($path)) {
            return null;
        }

        /** @var Store|array{store: Store, pdo?: PDO, table?: string} $config */
        $config = require $path;

        if ($config instanceof Store) {
            return new CacheerContext($config);
        }

        return new CacheerContext($config['store'], $config['pdo'] ?? null, $config['table'] ?? 'cacheer_store');
    }
}
