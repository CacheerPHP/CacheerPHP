<?php

declare(strict_types=1);

namespace Silviooosilva\CacheerPhp\Exceptions;

use Exception;
use JsonSerializable;

class BaseException extends Exception implements JsonSerializable
{
    /**
     * Details about the exception.
     *
     * @var array
     **/
    protected array $details;

    public function __construct(string $message = '', int $code = 0, ?Exception $previous = null, array $details = [])
    {
        parent::__construct($message, $code, $previous);
        $this->details = $details;
    }

    /**
     * Get the details of the exception.
     *
     * @return array
     **/
    public function getDetails()
    {
        return $this->details;
    }

    /**
     * Set the details of the exception.
     *
     * @param array $details
     * */
    public function setDetails(array $details)
    {
        $this->details = $details;
    }

    /**
     * Convert the exception to an array representation.
     *
     * @return array
     * */
    public function toArray()
    {
        return [
            'type'    => static::class,
            'message' => $this->getMessage(),
            'code'    => $this->getCode(),
            'details' => $this->getDetails(),
            'file'    => $this->getFile(),
            'line'    => $this->getLine(),
            'trace'   => $this->getTrace(),
        ];
    }

    /**
     * Convert the exception to a JSON serializable format.
     *
     * @return array
     * */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * Convert the exception to a JSON string.
     *
     * @return string
     * */
    public function toJson(int $options = 0)
    {
        return json_encode($this->toArray(), $options | JSON_THROW_ON_ERROR);
    }

    /**
     * Convert the exception to a string representation.
     *
     * @return string
     * */
    public function __toString()
    {
        return sprintf(
            "[%s] %s in %s on line %d\nDetails: %s",
            $this->getCode(),
            $this->getMessage(),
            $this->getFile(),
            $this->getLine(),
            json_encode($this->getDetails(), JSON_PRETTY_PRINT),
        );
    }
}
