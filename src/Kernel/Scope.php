<?php

declare(strict_types=1);

namespace Silviooosilva\CacheerPhp\Kernel;

use Silviooosilva\CacheerPhp\Exceptions\InvalidScopeException;

final readonly class Scope implements \Stringable
{
    private const MAX_SEGMENTS = 64;

    private const MAX_SEGMENT_BYTES = 255;

    /**
     * @param list<string> $segments
     */
    private function __construct(private array $segments)
    {
    }

    public static function root(): self
    {
        return new self([]);
    }

    public static function named(string $segment): self
    {
        return new self([self::validateSegment($segment)]);
    }

    /**
     * @param iterable<string> $segments
     */
    public static function fromSegments(iterable $segments): self
    {
        $validated = [];

        foreach ($segments as $segment) {
            $validated[] = self::validateSegment($segment);
        }

        if (count($validated) > self::MAX_SEGMENTS) {
            throw new InvalidScopeException(sprintf(
                'A scope may contain at most %d segments.',
                self::MAX_SEGMENTS,
            ));
        }

        return new self($validated);
    }

    public function child(string $segment): self
    {
        return $this->append(self::named($segment));
    }

    public function append(self $scope): self
    {
        return self::fromSegments([...$this->segments, ...$scope->segments]);
    }

    public function isRoot(): bool
    {
        return $this->segments === [];
    }

    /**
     * @return list<string>
     */
    public function segments(): array
    {
        return $this->segments;
    }

    public function contains(self $scope): bool
    {
        if (count($this->segments) > count($scope->segments)) {
            return false;
        }

        return array_slice($scope->segments, 0, count($this->segments)) === $this->segments;
    }

    /**
     * Collision-free internal identity. Backend-safe encoding belongs to the
     * storage pipeline introduced in Milestone 3.
     */
    public function identity(): string
    {
        return implode('', array_map(
            static fn (string $segment): string => strlen($segment) . ':' . $segment,
            $this->segments,
        ));
    }

    public function __toString(): string
    {
        return implode('/', $this->segments);
    }

    private static function validateSegment(string $segment): string
    {
        if ($segment === '') {
            throw new InvalidScopeException('A scope segment cannot be empty.');
        }

        if (strlen($segment) > self::MAX_SEGMENT_BYTES) {
            throw new InvalidScopeException(sprintf(
                'A scope segment cannot exceed %d bytes.',
                self::MAX_SEGMENT_BYTES,
            ));
        }

        if (str_contains($segment, '/') || preg_match('/[\x00-\x1F\x7F]/', $segment) === 1) {
            throw new InvalidScopeException('A scope segment cannot contain slashes or control characters.');
        }

        return $segment;
    }
}
