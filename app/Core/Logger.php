<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Daily file logger (storage/logs/app-YYYY-MM-DD.log).
 *
 * Users only ever see generic error pages; the details go here, tagged with a
 * per-request id so one request's lines can be found together. Never pass
 * passwords, raw tokens or session ids in $context (Phase 1, NFR-OPS-02).
 */
final class Logger
{
    private static ?string $requestId = null;

    /** Logs a failure that needs attention. */
    public static function error(string $message, array $context = []): void
    {
        self::write('ERROR', $message, $context);
    }

    /** Logs something suspicious that was handled (e.g. a CSRF failure). */
    public static function warning(string $message, array $context = []): void
    {
        self::write('WARNING', $message, $context);
    }

    /** Logs a normal but noteworthy event. */
    public static function info(string $message, array $context = []): void
    {
        self::write('INFO', $message, $context);
    }

    /** Random id shared by every log line written during this request. */
    public static function requestId(): string
    {
        return self::$requestId ??= bin2hex(random_bytes(8));
    }

    private static function write(string $level, string $message, array $context): void
    {
        $line = sprintf(
            "[%s] [%s] %s %s %s\n",
            gmdate('Y-m-d\TH:i:s\Z'),
            self::requestId(),
            $level,
            $message,
            $context === [] ? '' : json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR)
        );

        $file = BASE_PATH . '/storage/logs/app-' . gmdate('Y-m-d') . '.log';
        // @: a logging failure (full disk, permissions) must never break the request itself.
        if (@file_put_contents($file, $line, FILE_APPEND | LOCK_EX) === false) {
            error_log(rtrim($line));
        }
    }
}
