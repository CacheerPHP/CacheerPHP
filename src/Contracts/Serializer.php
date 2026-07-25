<?php

declare(strict_types=1);

namespace Silviooosilva\CacheerPhp\Contracts;

/**
 * Converts a cached value to and from a byte string for persistent storage.
 *
 * The id() is written into the value envelope so the reader can select the
 * matching serializer, even after the writer's default has changed.
 */
interface Serializer
{
    /**
     * Stable, envelope-safe identifier (ASCII, no 0x1E separators), e.g. "php".
     */
    public function id(): string;

    public function serialize(mixed $value): string;

    public function unserialize(string $payload): mixed;
}
