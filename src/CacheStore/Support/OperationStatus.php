<?php

namespace Silviooosilva\CacheerPhp\CacheStore\Support;

use Silviooosilva\CacheerPhp\Utils\CacheLogger;

/**
 * Tracks operation state and logs using a provided logger.
 */
final class OperationStatus
{
    /** @var string */
    private string $message = '';

    /** @var bool */
    private bool $success = false;

    /** @var CacheLogger */
    private CacheLogger $logger;

    /** @var string */
    private string $driverLabel;

    /**
     * @param CacheLogger $logger
     * @param string $driverLabel
     */
    public function __construct(CacheLogger $logger, string $driverLabel = 'redis')
    {
        $this->logger = $logger;
        $this->driverLabel = $driverLabel;
    }

    /**
     * @param string $message
     * @param bool $success
     * @param string $level
     * 
     * @return void
     */
    public function record(string $message, bool $success, string $level = 'debug'): void
    {
        $this->message = $message;
        $this->success = $success;

        $suffix = '';
        if ($this->driverLabel !== '') {
            $needle = "from {$this->driverLabel} driver";
            $suffix = str_contains($message, $needle) ? '' : " from {$this->driverLabel} driver.";
        }

        if (method_exists($this->logger, $level)) {
            $this->logger->{$level}("{$message}{$suffix}");
        } else {
            $this->logger->debug("{$message}{$suffix}");
        }
    }

    /**
     * @param string $message
     * @param bool $success
     * 
     * @return void
     */
    public function setState(string $message, bool $success): void
    {
        $this->message = $message;
        $this->success = $success;
    }

    /**
     * @return string
     */
    public function getMessage(): string
    {
        return $this->message;
    }

    /**
     * @return bool
     */
    public function isSuccess(): bool
    {
        return $this->success;
    }
}
