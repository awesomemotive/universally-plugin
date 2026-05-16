<?php
/**
 * Logging utility class
 *
 * @package Universally
 */

namespace Universally;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles logging for the plugin
 *
 * Provides static methods for different log levels:
 * - error: Critical errors that need attention
 * - warning: Warning messages
 * - info: Informational messages
 * - debug: Debug information for development
 *
 * All log messages are prefixed with 'Universally:' for easy filtering.
 */
class Log
{
    /**
     * Log levels
     */
    public const LEVEL_ERROR = 'ERROR';
    public const LEVEL_WARNING = 'WARNING';
    public const LEVEL_INFO = 'INFO';
    public const LEVEL_DEBUG = 'DEBUG';

    /**
     * Whether debug mode is enabled
     *
     * @var bool|null
     */
    private static ?bool $debugMode = null;

    /**
     * Check if debug mode is enabled
     *
     * @return bool True if debug mode is enabled.
     */
    private static function isDebugEnabled(): bool
    {
        if (self::$debugMode === null) {
            self::$debugMode = defined('UNIVERSALLY_DEBUG') && UNIVERSALLY_DEBUG;
        }
        return self::$debugMode;
    }

    /**
     * Log a message with a specific level
     *
     * @param string $level The log level (ERROR, WARNING, INFO, DEBUG).
     * @param string $message The message to log.
     * @param array $context Additional context data.
     * @return void
     */
    private static function log(string $level, string $message, array $context = []): void
    {
        // Skip debug messages if debug mode is not enabled
        if ($level === self::LEVEL_DEBUG && !self::isDebugEnabled()) {
            return;
        }

        // Format the log message
        $logMessage = sprintf('[Universally][%s] %s', $level, $message);

        // Add context if provided
        if (!empty($context)) {
            $logMessage .= ' | Context: ' . wp_json_encode($context, JSON_UNESCAPED_SLASHES);
        }

        // Always log ERROR, WARNING, and INFO messages
        // Only DEBUG messages respect UNIVERSALLY_DEBUG setting (handled above)
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- intentional logging
        error_log($logMessage);
    }

    /**
     * Log an error message
     *
     * Use for critical errors that need immediate attention.
     *
     * @param string $message The error message.
     * @param array $context Additional context data.
     * @return void
     */
    public static function error(string $message, array $context = []): void
    {
        self::log(self::LEVEL_ERROR, $message, $context);
    }

    /**
     * Log a warning message
     *
     * Use for non-critical issues that should be reviewed.
     *
     * @param string $message The warning message.
     * @param array $context Additional context data.
     * @return void
     */
    public static function warning(string $message, array $context = []): void
    {
        self::log(self::LEVEL_WARNING, $message, $context);
    }

    /**
     * Log an informational message
     *
     * Use for general informational messages about plugin operation.
     *
     * @param string $message The info message.
     * @param array $context Additional context data.
     * @return void
     */
    public static function info(string $message, array $context = []): void
    {
        self::log(self::LEVEL_INFO, $message, $context);
    }

    /**
     * Log a debug message
     *
     * Use for detailed debugging information during development.
     * Only logged when UNIVERSALLY_DEBUG constant is true.
     *
     * @param string $message The debug message.
     * @param array $context Additional context data.
     * @return void
     */
    public static function debug(string $message, array $context = []): void
    {
        self::log(self::LEVEL_DEBUG, $message, $context);
    }

    /**
     * Log an exception
     *
     * @param \Throwable $exception The exception to log.
     * @param string $message Optional custom message.
     * @return void
     */
    public static function exception(\Throwable $exception, string $message = 'Exception occurred'): void
    {
        self::error($message, [
            'exception' => get_class($exception),
            'message' => $exception->getMessage(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'trace' => $exception->getTraceAsString(),
        ]);
    }
}
