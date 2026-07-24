<?php

declare(strict_types=1);

namespace Silviooosilva\CacheerPhp\Kernel;

use Silviooosilva\CacheerPhp\Exceptions\InvalidKeyException;

final readonly class Key implements \Stringable
{
    private const MAX_BYTES = 1024;

    private function __construct(
        private string $value,
        private Scope $scope,
    ) {
    }

    public static function named(string $value): self
    {
        if ($value === '') {
            throw new InvalidKeyException('A cache key cannot be empty.');
        }

        if (strlen($value) > self::MAX_BYTES) {
            throw new InvalidKeyException(sprintf(
                'A cache key cannot exceed %d bytes.',
                self::MAX_BYTES,
            ));
        }

        if (preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw new InvalidKeyException('A cache key cannot contain control characters.');
        }

        return new self($value, Scope::root());
    }

    public function value(): string
    {
        return $this->value;
    }

    public function scope(): Scope
    {
        return $this->scope;
    }

    public function within(Scope $scope): self
    {
        return new self($this->value, $scope->append($this->scope));
    }

    public function identity(): string
    {
        return $this->scope->identity() . '|' . strlen($this->value) . ':' . $this->value;
    }

    public function __toString(): string
    {
        if ($this->scope->isRoot()) {
            return $this->value;
        }

        return $this->scope . '/' . $this->value;
    }
}
