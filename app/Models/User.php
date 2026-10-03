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
}
