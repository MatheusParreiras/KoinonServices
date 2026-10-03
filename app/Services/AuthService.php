<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Request;
use App\Models\AuditLog;
use App\Models\User;

/**
 * Credential checking for the login form.
 */
final class AuthService
{
    /**
     * bcrypt hash of a throwaway string. When the e-mail is unknown we still run
     * password_verify() against it, so "no such user" takes as long as "wrong
     * password" and response time does not reveal which e-mails exist.
     */
    private const DUMMY_HASH = '$2y$12$m0Zd22rlmFIfn6jg/q/Alug9GjO9s0K6bVR7MWeXK6AXppBOmNyZ.';

    public function __construct(
        private readonly User $users = new User(),
        private readonly AuditLog $audit = new AuditLog()
    ) {
    }

    /**
     * Checks an e-mail/password pair.
     *
     * Order matters: the "unverified e-mail" answer is only given AFTER the
     * password was proven correct. Otherwise anyone could type an e-mail and
     * learn that an unverified account exists for it.
     */
    public function attempt(string $email, string $password, Request $request): LoginResult
    {
        $email = User::normalizeEmail($email);
        $maxLength = (int) Config::get('security.password_max', 128);

        if ($email === '' || $password === '' || strlen($password) > $maxLength * 4) {
            return LoginResult::invalid();
        }

        $user = $this->users->findByEmail($email);

        // Unknown e-mail, or invited user who has not set a password yet.
        if ($user === null || $user['password_hash'] === null) {
            password_verify($password, self::DUMMY_HASH);
            $this->audit->record('auth.login_failed', $request, details: ['email' => $email, 'reason' => 'unknown']);

            return LoginResult::invalid();
        }

        $userId = (int) $user['id'];

        // While locked, even the right password is refused (and not checked against
        // the real hash), which stops brute force from continuing during the lock.
        if ($this->users->isLocked($user)) {
            password_verify($password, self::DUMMY_HASH);
            $this->audit->record('auth.login_locked', $request, $userId);

            return LoginResult::invalid();
        }

        if (!password_verify($password, (string) $user['password_hash'])) {
            $this->users->recordFailedLogin(
                $userId,
                (int) Config::get('security.login_max_attempts', 5),
                (int) Config::get('security.login_lock_minutes', 15)
            );
            $this->audit->record('auth.login_failed', $request, $userId, details: ['reason' => 'password']);

            return LoginResult::invalid();
        }

        if ($user['status'] === 'blocked' || $user['status'] === 'deleted') {
            $this->audit->record('auth.login_failed', $request, $userId, details: ['reason' => $user['status']]);

            return LoginResult::invalid();
        }

        // E-mail verification gate.
        if ($user['email_verified_at'] === null || $user['status'] !== 'active') {
            $this->audit->record('auth.login_unverified', $request, $userId);

            return LoginResult::unverified($user);
        }

        // Transparently upgrade the hash when PHP's default algorithm/cost changed.
        $algorithm = Config::get('security.password_algo', PASSWORD_DEFAULT);
        $options = Config::get('security.password_options', []);
        $rehashed = password_needs_rehash((string) $user['password_hash'], $algorithm, $options)
            ? password_hash($password, $algorithm, $options)
            : null;

        $this->users->recordSuccessfulLogin($userId, $rehashed);
        $this->audit->record('auth.login_succeeded', $request, $userId);

        return LoginResult::success($user);
    }
}
