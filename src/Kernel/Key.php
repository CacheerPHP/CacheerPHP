<?php

declare(strict_types=1);

namespace Silviooosilva\CacheerPhp\Kernel;

use Silviooosilva\CacheerPhp\Exceptions\InvalidKeyException;

/**
 * A validated cache key, optionally bound to a {@see Scope}.
 *
 * Validated once on construction so a backend never has to defend against an
 * empty, oversized, or control-character key.
 */
final readonly class Key implements \Stringable
{
    private const MAX_BYTES = 1024;

    /**
     * @param string $value
     * @param Scope $scope
     */
    private function __construct(
        private string $value,
        private Scope $scope,
    ) {
    }

    /**
     * @param string $value
     * @return Key
     */
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

    /**
     * @return string
     */
    public function value(): string
    {
        return $this->value;
    }

    /**
     * @return Scope
     */
    public function scope(): Scope
    {
        return $this->scope;
    }

    /**
     * @param Scope $scope
     * @return Key
     */
    public function within(Scope $scope): self
    {
        return new self($this->value, $scope->append($this->scope));
    }

    /**
     * @return string
     */
    public function identity(): string
    {
        return $this->scope->identity() . '|' . strlen($this->value) . ':' . $this->value;
    }

    /**
     * @return string
     */
    public function __toString(): string
    {
        if ($this->scope->isRoot()) {
            return $this->value;
        }

        return $this->scope . '/' . $this->value;
    }
}
