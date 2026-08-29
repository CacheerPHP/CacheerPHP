<?php

declare(strict_types=1);

namespace Silviooosilva\CacheerPhp\Console;

/**
 * Parsed positional arguments and --options for one CLI invocation.
 */
final class CommandInput
{
    /**
     * @param list<string>          $arguments
     * @param array<string, string> $options
     */
    public function __construct(
        private readonly array $arguments = [],
        private readonly array $options = [],
    ) {
    }

    /**
     * Parse a token list (already stripped of the command name) into arguments
     * and options: --flag, --key=value.
     *
     * @param list<string> $tokens
     * @return CommandInput
     */
    public static function fromTokens(array $tokens): self
    {
        $arguments = [];
        $options = [];

        foreach ($tokens as $token) {
            if (str_starts_with($token, '--')) {
                $pair = explode('=', substr($token, 2), 2);
                $options[$pair[0]] = $pair[1] ?? '1';

                continue;
            }

            $arguments[] = $token;
        }

        return new self($arguments, $options);
    }

    /**
     * @param int $index
     * @param ?string $default
     * @return ?string
     */
    public function argument(int $index, ?string $default = null): ?string
    {
        return $this->arguments[$index] ?? $default;
    }

    /**
     * @param string $name
     * @param ?string $default
     * @return ?string
     */
    public function option(string $name, ?string $default = null): ?string
    {
        return $this->options[$name] ?? $default;
    }

    /**
     * @param string $name
     * @return bool
     */
    public function flag(string $name): bool
    {
        return array_key_exists($name, $this->options);
    }
}
