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
    public function name(): string;

    public function description(): string;

    /**
     * @return int Exit code (0 = success).
     */
    public function run(CommandInput $input, CommandOutput $output, ?CacheerContext $context): int;
}
