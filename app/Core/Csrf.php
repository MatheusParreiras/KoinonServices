<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Synchronizer-token CSRF protection.
 *
 * One random token per session. Forms send it in a hidden "_csrf" field
 * (csrf_field()); fetch() sends it in the X-CSRF-Token header, read from the
 * <meta name="csrf-token"> tag. A malicious site can make the browser submit a
 * form here, but it cannot read the token, so the forged request is rejected.
 */
final class Csrf
{
    private const SESSION_KEY = '_csrf_token';

    /** Returns the session's token, creating it on first use. */
    public static function token(): string
    {
        $token = Session::get(self::SESSION_KEY);
        if (!is_string($token) || $token === '') {
            $token = bin2hex(random_bytes(32));
            Session::set(self::SESSION_KEY, $token);
        }

        return $token;
    }

    /** Replaces the token (on login), so a token seen before login is worthless. */
    public static function rotate(): void
    {
        Session::forget(self::SESSION_KEY);
        self::token();
    }

    /** Constant-time comparison: timing does not reveal how many characters matched. */
    public static function validate(?string $token): bool
    {
        $expected = Session::get(self::SESSION_KEY);

        return is_string($token) && $token !== ''
            && is_string($expected) && $expected !== ''
            && hash_equals($expected, $token);
    }
}
