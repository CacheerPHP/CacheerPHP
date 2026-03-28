<?php

namespace Silviooosilva\CacheerPhp\Utils;

use Psr\Log\AbstractLogger;
use Stringable;

/**
 * Class CacheLogger
 *
 * A PSR-3 compliant logger with automatic log-file rotation.
 *
 * v5.0.0 changes:
 *  - Now extends \Psr\Log\AbstractLogger so it satisfies \Psr\Log\LoggerInterface
 *    and can be injected wherever a standard PSR-3 logger is expected.
 *  - Fixed log-rotation bug: rotated files are now created in the same directory
 *    as the source log file, not in the current working directory.
 *  - Constructor parameters are now typed explicitly.
 *
 * @author Sílvio Silva <https://github.com/silviooosilva>
 * @package Silviooosilva\CacheerPhp
 */
class CacheLogger extends AbstractLogger
{
    /**
     * Ordered list of log levels (lowest → highest).
     *
     * @var string[]
     */
    private array $logLevels = ['DEBUG', 'INFO', 'WARNING', 'ERROR'];

    /**
     * CacheLogger constructor.
     *
     * @param string $logFile    Path to the log file (absolute or relative to CWD).
     * @param int    $maxFileSize Maximum log size in bytes before rotation (default: 5 MB).
     * @param string $logLevel   Minimum level to record (DEBUG, INFO, WARNING, ERROR).
     */
    public function __construct(
        private string $logFile      = 'cacheer.log',
        private int    $maxFileSize  = 5 * 1024 * 1024,
        private string $logLevel     = 'DEBUG'
    ) {
        $this->logLevel = strtoupper($logLevel);
    }

    /**
     * Logs a message at an arbitrary level.
     *
     * Required by \Psr\Log\AbstractLogger. All convenience methods
     * (debug, info, warning, error, …) provided by AbstractLogger delegate here.
     *
     * @param mixed             $level   One of the Psr\Log\LogLevel constants or a plain string.
     * @param string|Stringable $message The log message.
     * @param array             $context Context values to interpolate into the message.
     * @return void
     */
    public function log(mixed $level, string|Stringable $message, array $context = []): void
    {
        $levelStr = strtoupper((string) $level);

        if (!$this->shouldLog($levelStr)) {
            return;
        }

        $this->rotateLog();

        $message    = $this->interpolate((string) $message, $context);
        $date       = date('Y-m-d H:i:s');
        $logMessage = "[$date] [$levelStr] $message" . PHP_EOL;
        file_put_contents($this->logFile, $logMessage, FILE_APPEND);
    }

    /**
     * Checks if the given level is at or above the configured minimum level.
     *
     * @param string $level
     * @return bool
     */
    private function shouldLog(string $level): bool
    {
        $levelIdx  = array_search($level,           $this->logLevels, true);
        $configIdx = array_search($this->logLevel,  $this->logLevels, true);

        return $levelIdx !== false && $levelIdx >= $configIdx;
    }

    /**
     * Rotates the log file if its size exceeds the configured maximum.
     *
     * The rotated file is placed in the same directory as the original log
     * (the previous implementation always wrote to the current working directory).
     *
     * @return void
     */
    private function rotateLog(): void
    {
        if (!file_exists($this->logFile) || filesize($this->logFile) < $this->maxFileSize) {
            return;
        }

        $dir  = dirname($this->logFile);
        $base = basename($this->logFile, '.log');
        $date = date('Y-m-d_H-i-s');

        rename($this->logFile, $dir . DIRECTORY_SEPARATOR . "{$base}_{$date}.log");
    }

    /**
     * Interpolates context values into the message placeholders.
     *
     * Implements the PSR-3 placeholder convention: {key} → context['key'].
     *
     * @param string  $message
     * @param mixed[] $context
     * @return string
     */
    private function interpolate(string $message, array $context): string
    {
        $replace = [];
        foreach ($context as $key => $val) {
            if (is_string($val) || (is_object($val) && method_exists($val, '__toString'))) {
                $replace['{' . $key . '}'] = (string) $val;
            }
        }
        return strtr($message, $replace);
    }
}
