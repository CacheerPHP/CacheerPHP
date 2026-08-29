<?php

declare(strict_types=1);

namespace Silviooosilva\CacheerPhp\Console;

/**
 * One CLI subcommand. Commands are pure: they read the input, act on the
 * context, write to the output, and return a Unix exit code — never touching
 * globals or echoing directly, so they are fully testable.
 */
interface Command
{
    /**
     * @return string
     */
    public function name(): string;

    /**
     * @return string
     */
    public function description(): string;

    /**
     * @param CommandInput $input
     * @param CommandOutput $output
     * @param ?CacheerContext $context
     * @return int Exit code (0 = success).
     */
    public function run(CommandInput $input, CommandOutput $output, ?CacheerContext $context): int;
}
