<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Minimal .env loader.
 *
 * Secrets live in a .env file next to (not inside) public/, so they are never
 * downloadable and never committed. Parsing KEY=VALUE lines is a few lines of
 * code, so no third-party package is used for it (Phase 1, NFR-LIB-05).
 */
final class Env
{
    /** @var array<string, string> Values read from the .env file. */
    private static array $values = [];

    /**
     * Reads a .env file. A missing file is not an error: in production the
     * variables may come from the web server or container environment instead.
     */
    public static function load(string $file): void
    {
        if (!is_file($file) || !is_readable($file)) {
            return;
        }

        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = array_map('trim', explode('=', $line, 2));
            $quoted = strlen($value) >= 2
                && ($value[0] === '"' || $value[0] === "'")
                && $value[-1] === $value[0];
            self::$values[$key] = $quoted ? substr($value, 1, -1) : $value;
        }
    }

    /**
     * Returns a variable. Real environment variables win over the .env file so
     * that deployment tooling can override a value without editing files.
     */
    public static function get(string $key, ?string $default = null): ?string
    {
        $fromEnvironment = getenv($key);
        if ($fromEnvironment !== false) {
            return $fromEnvironment;
        }

        return self::$values[$key] ?? $default;
    }

    /** Returns a variable interpreted as a boolean ("1", "true", "yes", "on"). */
    public static function bool(string $key, bool $default = false): bool
    {
        $value = self::get($key);
        if ($value === null || $value === '') {
            return $default;
        }

        return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
    }

    /** Returns a variable interpreted as an integer. */
    public static function int(string $key, int $default): int
    {
        $value = self::get($key);

        return ($value !== null && is_numeric($value)) ? (int) $value : $default;
    }
}
