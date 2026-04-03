<?php

namespace Silviooosilva\CacheerPhp\Exceptions;

use Exception;

class CacheFileException extends BaseException
{
    private static string $before = '<File Cache Store Exception>';

    /**
     * Creates a new instance of CacheFileException.
     *
     * @param string $message
     * @param int $code
     * @param Exception|null $previous
     * @param array $details
     * @return self
     */
    public static function create(string $message = '', int $code = 0, ?Exception $previous = null, array $details = [])
    {
        return new self(self::getBefore() . ': ' . $message, $code, $previous, $details);
    }

    /**
     * Gets the static text that will be prepended to the exception message.
     *
     * @return string
     */
    public static function getBefore()
    {
        return self::$before;
    }

    /**
     * Sets the static text that will be prepended to the exception message.
     *
     * @param string $text
     */
    public static function setBefore(string $text)
    {
        self::$before = $text;
    }

    /*
    * Converts the exception to an array representation.
    *
    * @return array
    */
    public function toArray()
    {
        return parent::toArray();
    }

    /**
     * Converts the exception to a JSON serializable format.
     *
     * @return string
     */
    public function jsonSerialize(): array
    {
        return parent::jsonSerialize();
    }

    /**
     * Converts the exception to a JSON string.
     *
     * @param int $options
     * @return string
     */
    public function toJson(int $options = 0)
    {
        return parent::toJson($options);
    }
}
