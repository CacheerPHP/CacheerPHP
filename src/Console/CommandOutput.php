<?php

declare(strict_types=1);

namespace Silviooosilva\CacheerPhp\Console;

/**
 * Collects a command's output as both human-readable lines and structured data,
 * rendering whichever the invocation asked for (--json selects the data form).
 */
final class CommandOutput
{
    /**
     * @var list<string>
     */
    private array $lines = [];

    /**
     * @var array<string, mixed>
     */
    private array $data = [];

    public function __construct(public readonly bool $json = false)
    {
    }

    public function line(string $line = ''): self
    {
        $this->lines[] = $line;

        return $this;
    }

    public function set(string $key, mixed $value): self
    {
        $this->data[$key] = $value;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function data(): array
    {
        return $this->data;
    }

    /**
     * @return list<string>
     */
    public function textLines(): array
    {
        return $this->lines;
    }

    public function render(): string
    {
        if ($this->json) {
            return json_encode($this->data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}';
        }

        return implode(PHP_EOL, $this->lines);
    }
}
