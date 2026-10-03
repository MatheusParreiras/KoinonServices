<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Hardened wrapper around PHP's native sessions.
 *
 * Security decisions:
 *  - Cookie: HttpOnly (JavaScript cannot read it, which limits XSS session theft),
 *    Secure (HTTPS only), SameSite=Lax (not sent on cross-site POSTs, which is a
 *    second layer of CSRF defence), lifetime 0 (deleted when the browser closes).
 *  - use_strict_mode: PHP refuses session ids it did not create, which blocks
 *    session fixation through a planted cookie.
 *  - Idle and absolute timeouts are enforced here, server-side, because the
 *    cookie lifetime alone is controlled by the client.
 *  - Files are stored in storage/sessions, outside the web root.
 */
final class Session
{
    private const FLASH_KEY = '_flash';

    /** Starts the session with the hardened settings and enforces timeouts. */
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        $config = Config::get('session');

        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');
        ini_set('session.cookie_httponly', '1');
        ini_set('session.gc_maxlifetime', (string) ($config['idle_minutes'] * 60));

        session_save_path($config['save_path']);
        session_name($config['name']);
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'domain'   => '',
            'secure'   => (bool) $config['secure'],
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        session_start();
        self::enforceTimeouts($config);
    }

    /**
     * Issues a new session id and deletes the old session file. Called on
     * login, logout and tenant switch so an id observed before a privilege
     * change is useless afterwards.
     */
    public static function regenerate(): void
    {
        session_regenerate_id(true);
    }

    /** Removes every value from the current session (keeps the session itself). */
    public static function clear(): void
    {
        $_SESSION = [];
    }

    /**
     * Fully ends the session: empties it, expires the cookie in the browser and
     * deletes the server-side file. A fresh, empty session is started so the
     * caller can still flash a message (e.g. "you have logged out").
     */
    public static function destroy(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }

        $_SESSION = [];
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires'  => time() - 42000,
            'path'     => $params['path'],
            'domain'   => $params['domain'],
            'secure'   => $params['secure'],
            'httponly' => $params['httponly'],
            'samesite' => $params['samesite'],
        ]);
        session_destroy();

        // Strict mode rejects the old id still present in the request cookie,
        // so this creates a brand-new id.
        self::start();
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public static function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public static function forget(string ...$keys): void
    {
        foreach ($keys as $key) {
            unset($_SESSION[$key]);
        }
    }

    /** Stores a value that survives exactly one redirect (messages, old input). */
    public static function flash(string $key, string $value): void
    {
        $_SESSION[self::FLASH_KEY][$key] = $value;
    }

    /** Returns and removes a flashed value. */
    public static function pullFlash(string $key): ?string
    {
        $value = $_SESSION[self::FLASH_KEY][$key] ?? null;
        unset($_SESSION[self::FLASH_KEY][$key]);

        return is_string($value) ? $value : null;
    }

    /**
     * Flashes structured data for one redirect, e.g. validation errors and the
     * previously submitted input, so a form can be redisplayed after a POST.
     *
     * @param array<string, mixed> $data
     */
    public static function flashData(string $key, array $data): void
    {
        $_SESSION[self::FLASH_KEY . '_data'][$key] = $data;
    }

    /**
     * Returns and removes flashed structured data ([] when absent).
     *
     * @return array<string, mixed>
     */
    public static function pullFlashData(string $key): array
    {
        $value = $_SESSION[self::FLASH_KEY . '_data'][$key] ?? [];
        unset($_SESSION[self::FLASH_KEY . '_data'][$key]);

        return is_array($value) ? $value : [];
    }

    /**
     * Returns and removes several flashed values at once, keyed by name.
     *
     * @param list<string> $keys
     * @return array<string, string>
     */
    public static function pullFlashes(array $keys): array
    {
        $messages = [];
        foreach ($keys as $key) {
            $value = self::pullFlash($key);
            if ($value !== null) {
                $messages[$key] = $value;
            }
        }

        return $messages;
    }

    /**
     * Ends a logged-in session after SESSION_IDLE_MINUTES of inactivity or
     * SESSION_ABSOLUTE_HOURS since login, whichever comes first.
     *
     * @param array<string, mixed> $config
     */
    private static function enforceTimeouts(array $config): void
    {
        $now = time();
        $lastActivity = $_SESSION['_last_activity'] ?? null;
        $createdAt = $_SESSION['_created_at'] ?? null;

        $idleExpired = is_int($lastActivity) && $now - $lastActivity > $config['idle_minutes'] * 60;
        $absoluteExpired = is_int($createdAt) && $now - $createdAt > $config['absolute_hours'] * 3600;

        if (isset($_SESSION['user_id']) && ($idleExpired || $absoluteExpired)) {
            self::clear();
            self::regenerate();
            self::flash('warning', 'Sua sessão expirou por inatividade. Entre novamente.');
        }

        $_SESSION['_created_at'] ??= $now;
        $_SESSION['_last_activity'] = $now;
    }
}
