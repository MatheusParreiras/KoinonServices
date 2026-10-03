<?php

/**
 * Global view helpers, autoloaded through composer.json "files".
 */

declare(strict_types=1);

use App\Core\Csrf;

if (!function_exists('e')) {
    /**
     * Escapes a value for HTML text and attribute contexts.
     *
     * ENT_QUOTES escapes both quote types (safe inside attributes);
     * ENT_SUBSTITUTE replaces invalid UTF-8 instead of returning an empty string.
     */
    function e(string|int|float|bool|null $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('csrf_token')) {
    /** The current session's CSRF token (for the <meta> tag read by JavaScript). */
    function csrf_token(): string
    {
        return Csrf::token();
    }
}

if (!function_exists('csrf_field')) {
    /** Hidden input carrying the CSRF token; required in every POST form. */
    function csrf_field(): string
    {
        return '<input type="hidden" name="_csrf" value="' . e(Csrf::token()) . '">';
    }
}

if (!function_exists('asset')) {
    /**
     * URL of a file in public/assets with a cache-busting version (file mtime),
     * so browsers can cache assets for long periods yet pick up new deployments.
     */
    function asset(string $path): string
    {
        $path = ltrim($path, '/');
        $file = BASE_PATH . '/public/assets/' . $path;
        $version = is_file($file) ? (string) filemtime($file) : '0';

        return '/assets/' . $path . '?v=' . $version;
    }
}
