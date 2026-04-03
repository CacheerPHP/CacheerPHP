<?php

namespace Silviooosilva\CacheerPhp\Utils;

/**
 * Class CacheDataFormatter
 * @author Sílvio Silva <https://github.com/silviooosilva>
 * @package Silviooosilva\CacheerPhp
 */
class CacheDataFormatter
{
    /**
     * @param mixed $data
     */
    private mixed $data;

    /**
    * CacheDataFormatter constructor.
    *
    * @param mixed $data
    */
    public function __construct(mixed $data)
    {
        $this->data = $data;
    }

    /**
     * Converts the data to a JSON string.
     *
     * Uses JSON_THROW_ON_ERROR so a \JsonException is raised on encoding
     * failure instead of silently returning false.
     *
     * @return string
     * @throws \JsonException
     */
    public function toJson(): string
    {
        return json_encode(
            $this->data,
            JSON_PRETTY_PRINT |
                JSON_UNESCAPED_UNICODE |
                JSON_UNESCAPED_SLASHES |
                JSON_THROW_ON_ERROR,
        );
    }

    /**
    * Converts the data to an array.
    *
    * @return array
    */
    public function toArray(): array
    {
        return (array)$this->data;
    }

    /**
    * Converts the data to a string.
    *
    * @return string
    */
    public function toString(): string
    {
        return (string)$this->data;
    }

    /**
    * Converts the data to an object.
    *
    * @return object
    */
    public function toObject(): object
    {
        return (object)$this->data;
    }
}
