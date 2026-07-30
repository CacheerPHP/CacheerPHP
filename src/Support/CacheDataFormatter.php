<?php

declare(strict_types=1);

namespace Silviooosilva\CacheerPhp\Support;

use JsonException;

/**
 * Formats a cached value as JSON, an array, an object, or a string.
 *
 * A standalone, stateless helper — it never touches the cache. There are two
 * ways to reach it, so pick whichever reads best:
 *
 *     // 1) Wrap any value explicitly.
 *     $json = (new CacheDataFormatter($cache->get('user:1')))->toJson();
 *
 *     // 2) Read through a formatted view, so get() returns one directly.
 *     $json = $cache->formatted()->get('user:1')->toJson();
 *
 * `toString()`/`toJson()` suit scalars and arrays; casting a non-serializable
 * value follows PHP's normal casting rules.
 */
final readonly class CacheDataFormatter
{
    public function __construct(private mixed $data)
    {
    }

    /**
     * Encode as pretty JSON. Raises a JsonException on failure instead of
     * silently returning false.
     *
     * @throws JsonException
     */
    public function toJson(): string
    {
        return json_encode(
            $this->data,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );
    }

    /**
     * @return array<array-key, mixed>
     */
    public function toArray(): array
    {
        return (array) $this->data;
    }

    public function toString(): string
    {
        return (string) $this->data;
    }

    public function toObject(): object
    {
        return (object) $this->data;
    }

    /**
     * The raw, unformatted value.
     */
    public function value(): mixed
    {
        return $this->data;
    }
}
