<?php

declare(strict_types=1);

namespace App\Core;

use App\Models\User;

/**
 * The authenticated user of the current request.
 *
 * Session layout after login:
 *   user_id          int       users.id
 *   session_version  int       copy of users.session_version at login
 *   condominium_id   int|null  active membership's tenant; NULL for a Super Admin
 *   role_id          int|null  condominium_users.role_id; NULL for a Super Admin
 *   role_code        str|null  roles.code ("manager", "concierge", "resident")
 *   admin_condominium_id int   (Super Admin only) tenant chosen in the selector
 *
 * The user row is re-read from the database on every request. A blocked user,
 * or a password change (which increments users.session_version), therefore
 * ends every existing session immediately, not when the cookie expires.
 */
final class Auth
{
    /** Pseudo-role used in role checks for users.is_super_admin = TRUE. */
    public const SUPER_ADMIN = 'super_admin';

    private const ROLE_LABELS = [
        self::SUPER_ADMIN => 'Super Admin',
        'manager'         => 'Síndico / Administração',
        'concierge'       => 'Portaria / Segurança',
        'resident'        => 'Morador',
    ];

    /** @var array<string, mixed>|null */
    private static ?array $user = null;
    private static bool $resolved = false;

    /**
     * Starts an authenticated session.
     *
     * @param array<string, mixed>      $user       Row from `users`.
     * @param array<string, mixed>|null $membership Active membership to enter, or null
     *                                              (Super Admin, or user who must choose).
     */
    public static function login(array $user, ?array $membership): void
    {
        // New session id: an id planted or observed before login is now worthless
        // (session fixation). Old data (e.g. pre-login values) is dropped too.
        Session::regenerate();
        Session::clear();
        Csrf::rotate();

        Session::set('user_id', (int) $user['id']);
        Session::set('session_version', (int) $user['session_version']);
        Session::set('_created_at', time()); // absolute timeout counts from login

        self::enterMembership($membership);

        self::$user = $user;
        self::$resolved = true;
    }

    /**
     * Stores the tenant and role of a membership in the session (or clears them).
     *
     * @param array<string, mixed>|null $membership Row from Membership::findActive().
     */
    public static function enterMembership(?array $membership): void
    {
        Session::set('condominium_id', $membership === null ? null : (int) $membership['condominium_id']);
        Session::set('role_id', $membership === null ? null : (int) $membership['role_id']);
        Session::set('role_code', $membership === null ? null : (string) $membership['role_code']);
    }

    /** Ends the session completely (server file, cookie and in-memory state). */
    public static function logout(): void
    {
        self::$user = null;
        self::$resolved = true;
        Session::destroy();
    }

    /**
     * The logged-in user's row, or null. A session whose user was blocked or
     * deleted, or whose session_version is stale, is destroyed here.
     *
     * @return array<string, mixed>|null
     */
    public static function user(): ?array
    {
        if (self::$resolved) {
            return self::$user;
        }
        self::$resolved = true;

        $userId = Session::get('user_id');
        if (!is_int($userId)) {
            return null;
        }

        $user = (new User())->find($userId);
        $valid = $user !== null
            && $user['status'] === 'active'
            && (int) $user['session_version'] === Session::get('session_version');

        if (!$valid) {
            Logger::info('Session invalidated', ['user_id' => $userId]);
            self::logout();

            return null;
        }

        return self::$user = $user;
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    public static function id(): ?int
    {
        $user = self::user();

        return $user === null ? null : (int) $user['id'];
    }

    /** Read from the database row, never from the session or the request. */
    public static function isSuperAdmin(): bool
    {
        return (bool) (self::user()['is_super_admin'] ?? false);
    }

    /** "super_admin", the membership's role code, or null when no tenant was entered. */
    public static function roleCode(): ?string
    {
        if (!self::check()) {
            return null;
        }
        if (self::isSuperAdmin()) {
            return self::SUPER_ADMIN;
        }
        $role = Session::get('role_code');

        return is_string($role) ? $role : null;
    }

    /**
     * True when the current user has one of the given roles.
     *
     * @param list<string> $roles e.g. [Auth::SUPER_ADMIN, 'manager']
     */
    public static function hasRole(array $roles): bool
    {
        $role = self::roleCode();

        return $role !== null && in_array($role, $roles, true);
    }

    /** Human-readable name of the current role, for the header. */
    public static function roleLabel(): ?string
    {
        $role = self::roleCode();

        return $role === null ? null : (self::ROLE_LABELS[$role] ?? $role);
    }
}
