<?php

/**
 * Simple Logger Class
 * 
 * Basic file-based logging for the application
 */
class Logger
{
    private $logFile;
    private $logLevel;

    const LEVEL_DEBUG = 0;
    const LEVEL_INFO = 1;
    const LEVEL_WARNING = 2;
    const LEVEL_ERROR = 3;

    public function __construct(string $logFile = 'logs/app.log', int $logLevel = self::LEVEL_INFO)
    {
        $this->logFile = $logFile;
        $this->logLevel = $logLevel;

        // Create log directory if it doesn't exist
        $logDir = dirname($logFile);
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
    }

    /**
     * Log a debug message
     */
    public function debug(string $message): void
    {
        $this->log(self::LEVEL_DEBUG, $message);
    }

    /**
     * Log an info message
     */
    public function info(string $message): void
    {
        $this->log(self::LEVEL_INFO, $message);
    }

    /**
     * Log a warning message
     */
    public function warning(string $message): void
    {
        $this->log(self::LEVEL_WARNING, $message);
    }

    /**
     * Log an error message
     */
    public function error(string $message): void
    {
        $this->log(self::LEVEL_ERROR, $message);
    }

    /**
     * Write log message to file
     */
    private function log(int $level, string $message): void
    {
        if ($level < $this->logLevel) {
            return;
        }

        $levelNames = [
            self::LEVEL_DEBUG => 'DEBUG',
            self::LEVEL_INFO => 'INFO',
            self::LEVEL_WARNING => 'WARNING',
            self::LEVEL_ERROR => 'ERROR'
        ];

        $timestamp = date('Y-m-d H:i:s');
        $levelName = $levelNames[$level] ?? 'UNKNOWN';
        $logMessage = "[{$timestamp}] [{$levelName}] {$message}" . PHP_EOL;

        file_put_contents($this->logFile, $logMessage, FILE_APPEND | LOCK_EX);
    }
}
