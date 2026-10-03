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

if (!function_exists('money_br')) {
    /**
     * Formats a DECIMAL string from the database as Brazilian currency
     * ("1234.50" -> "R$ 1.234,50") using string arithmetic only, no float.
     */
    function money_br(string $decimal): string
    {
        $negative = str_starts_with($decimal, '-');
        [$whole, $fraction] = array_pad(explode('.', ltrim($decimal, '-'), 2), 2, '00');
        $whole = number_format((int) $whole, 0, ',', '.');

        return ($negative ? '-' : '') . 'R$ ' . $whole . ',' . str_pad(substr($fraction, 0, 2), 2, '0');
    }
}

if (!function_exists('date_br')) {
    /** Formats "Y-m-d" or "Y-m-d H:i:s" as "d/m/Y" or "d/m/Y H:i" (no time-zone conversion). */
    function date_br(?string $value, bool $withTime = false): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        $date = new DateTimeImmutable($value);

        return $date->format($withTime ? 'd/m/Y H:i' : 'd/m/Y');
    }
}

if (!function_exists('local_datetime')) {
    /**
     * Converts a UTC DATETIME from the database (created_at, entry_at...) to the
     * condominium's local time for display. Do NOT use it for reservation times,
     * which are already stored as local wall-clock values.
     */
    function local_datetime(?string $utc, string $format = 'd/m/Y H:i'): string
    {
        if ($utc === null || $utc === '') {
            return '';
        }
        $date = new DateTimeImmutable($utc, new DateTimeZone('UTC'));

        return $date->setTimezone(App\Core\TenantContext::timezone())->format($format);
    }
}

if (!function_exists('mask_document')) {
    /**
     * Masks an ID number (RG) for display: only the last 3 characters remain.
     *
     * LGPD data minimisation: lists and screens need to tell visitors apart, not
     * to expose their full ID. The full number is only compared server-side.
     */
    function mask_document(string $document): string
    {
        $visible = mb_substr($document, -3);

        return str_repeat('•', max(3, mb_strlen($document) - 3)) . $visible;
    }
}

if (!function_exists('field_error')) {
    /**
     * Renders the error message of one form field, or nothing.
     *
     * @param array<string, string> $errors
     */
    function field_error(array $errors, string $field): string
    {
        return isset($errors[$field])
            ? '<span class="form__error">' . e($errors[$field]) . '</span>'
            : '';
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
