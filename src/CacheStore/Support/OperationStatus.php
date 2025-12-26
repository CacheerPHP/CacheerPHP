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

    /**
     * @param CacheLogger $logger
     */
    public function __construct(CacheLogger $logger)
    {
        $this->logger = $logger;
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

        if (method_exists($this->logger, $level)) {
            $this->logger->{$level}("{$message} from redis driver.");
        } else {
            $this->logger->debug("{$message} from redis driver.");
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
