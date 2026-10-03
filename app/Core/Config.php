<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Read-only access to the arrays returned by config/*.php.
 *
 * Usage: Config::get('database.host'). The first segment is the file name.
 */
final class Config
{
    /** @var array<string, mixed> */
    private static array $items = [];

    /** Loads every PHP file in the directory; each must return an array. */
    public static function load(string $directory): void
    {
        foreach (glob($directory . '/*.php') ?: [] as $file) {
            self::$items[basename($file, '.php')] = require $file;
        }
    }

    /** Returns a value by dot-notation key, or $default when it does not exist. */
    public static function get(string $key, mixed $default = null): mixed
    {
        $value = self::$items;
        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }
}
