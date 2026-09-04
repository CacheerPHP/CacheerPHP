<?php

declare(strict_types=1);

namespace Silviooosilva\CacheerPhp\Support;

use JsonException;

final readonly class CacheDataFormatter
{
    /**
     * @param mixed $data
     */
    public function __construct(private mixed $data)
    {
    }

    /**
     * Encode as pretty JSON. Raises a JsonException on failure instead of
     * silently returning false.
     *
     * @return string
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
     * @return array
     */
    public function toArray(): array
    {
        return (array) $this->data;
    }

    /**
     * @return string
     */
    public function toString(): string
    {
        return (string) $this->data;
    }

    /**
     * @return object
     */
    public function toObject(): object
    {
        return (object) $this->data;
    }

    /**
     * The raw, unformatted value.
     *
     * @return mixed
     */
    public function value(): mixed
    {
        return $this->data;
    }
}
