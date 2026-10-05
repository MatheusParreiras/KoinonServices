<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Model;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Global identity table `users` (not tenant-scoped: one person, one row,
 * possibly members of several condominiums through condominium_users).
 */
final class User extends Model
{
    protected string $table = 'users';

    protected array $fillable = [
        'full_name',
        'email',
        'password_hash',
        'phone',
        'is_super_admin',
        'status',
        'email_verified_at',
    ];

    /** E-mails are stored trimmed and lower-cased; callers pass raw input. */
    public static function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    public function findByEmail(string $email): ?array
    {
        return $this->fetchOne(
            'SELECT * FROM users WHERE email = :email LIMIT 1',
            ['email' => self::normalizeEmail($email)]
        );
    }

    /** Locks the user row for the rest of the current transaction. */
    public function findForUpdate(int $id): ?array
    {
        return $this->fetchOne('SELECT * FROM users WHERE id = :id FOR UPDATE', ['id' => $id]);
    }

    /** True while users.locked_until (UTC) is in the future. */
    public function isLocked(array $user): bool
    {
        if ($user['locked_until'] === null) {
            return false;
        }
        $until = new DateTimeImmutable((string) $user['locked_until'], new DateTimeZone('UTC'));

        return $until > new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    /**
     * Counts a failed password and locks the account when the limit is reached.
     *
     * One atomic UPDATE, so parallel guesses cannot race past the limit.
     * locked_until is assigned BEFORE failed_login_count because MySQL evaluates
     * SET clauses left to right: it must still see the old counter value.
     */
    public function recordFailedLogin(int $id, int $maxAttempts, int $lockMinutes): void
    {
        $this->execute(
            'UPDATE users
                SET locked_until = IF(failed_login_count + 1 >= :max_a,
                                      UTC_TIMESTAMP() + INTERVAL :lock_minutes MINUTE,
                                      locked_until),
                    failed_login_count = IF(failed_login_count + 1 >= :max_b, 0, failed_login_count + 1)
              WHERE id = :id',
            ['max_a' => $maxAttempts, 'max_b' => $maxAttempts, 'lock_minutes' => $lockMinutes, 'id' => $id]
        );
    }

    /** Resets the failure counter and, when given, stores an upgraded password hash. */
    public function recordSuccessfulLogin(int $id, ?string $rehashed): void
    {
        $this->execute(
            'UPDATE users
                SET failed_login_count = 0,
                    locked_until = NULL,
                    last_login_at = UTC_TIMESTAMP(),
                    password_hash = COALESCE(:rehashed, password_hash)
              WHERE id = :id',
            ['rehashed' => $rehashed, 'id' => $id]
        );
    }

    /**
     * Activates the account after e-mail verification. $passwordHash is set for
     * invited users, who choose their password on the activation page.
     */
    public function markEmailVerified(int $id, ?string $passwordHash): void
    {
        $this->execute(
            "UPDATE users
                SET email_verified_at = UTC_TIMESTAMP(),
                    status = 'active',
                    password_hash = COALESCE(:password_hash, password_hash)
              WHERE id = :id",
            ['password_hash' => $passwordHash, 'id' => $id]
        );
    }

    /**
     * Stores a new password hash and increments session_version, which ends
     * every existing session of the user on their next request (Auth::user()).
     * The lockout counter is cleared: whoever proved ownership of the account
     * (current password or e-mailed token) should not stay locked out.
     */
    public function updatePassword(int $id, string $passwordHash): void
    {
        $this->execute(
            'UPDATE users
                SET password_hash = :password_hash,
                    session_version = session_version + 1,
                    failed_login_count = 0,
                    locked_until = NULL
              WHERE id = :id',
            ['password_hash' => $passwordHash, 'id' => $id]
        );
    }

    /** Name and phone, edited by the user themself (never by a manager: users is global). */
    public function updateProfile(int $id, string $fullName, ?string $phone): void
    {
        $this->execute(
            'UPDATE users SET full_name = :full_name, phone = :phone WHERE id = :id',
            ['full_name' => $fullName, 'phone' => $phone, 'id' => $id]
        );
    }

    /** Relative path under storage/uploads, or null to remove the avatar. */
    public function updateAvatar(int $id, ?string $avatarPath): void
    {
        $this->execute(
            'UPDATE users SET avatar_path = :avatar_path WHERE id = :id',
            ['avatar_path' => $avatarPath, 'id' => $id]
        );
    }

    /**
     * Moves the account to a confirmed new address. The new address was proven
     * by the e-mailed token, so it is verified now; other sessions end because
     * the login identifier changed.
     */
    public function changeEmail(int $id, string $newEmail): void
    {
        $this->execute(
            'UPDATE users
                SET email = :email,
                    email_verified_at = UTC_TIMESTAMP(),
                    session_version = session_version + 1
              WHERE id = :id',
            ['email' => self::normalizeEmail($newEmail), 'id' => $id]
        );
    }
}
