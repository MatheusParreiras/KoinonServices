<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Database;
use App\Core\Request;
use App\Mail\Mailer;
use App\Models\PasswordResetToken;
use App\Models\User;

/**
 * "Forgot password" and "reset password" (Phase 5).
 *
 *  - request() is SILENT: the controller shows the same message whether the
 *    e-mail exists, is inactive, or was rate-limited, so the form cannot be used
 *    to discover which e-mails have accounts (account enumeration).
 *  - inspect() is read-only (GET landing page): mail scanners that open links
 *    must not burn the single-use token.
 *  - reset() locks the token row, re-checks it, stores the new password,
 *    increments session_version (every session of the account ends) and revokes
 *    every other outstanding reset token, all in one transaction.
 */
final class PasswordResetService
{
    public const VALID = 'valid';
    public const EXPIRED = 'expired';
    public const INVALID = 'invalid';

    public function __construct(
        private readonly Request $request,
        private readonly PasswordResetToken $tokens = new PasswordResetToken(),
        private readonly User $users = new User(),
        private readonly TokenService $tokenService = new TokenService(),
        private readonly RateLimiter $limiter = new RateLimiter(),
        private readonly Mailer $mailer = new Mailer()
    ) {
    }

    /** Sends a reset link when the account exists and is active. Never reveals the outcome. */
    public function request(string $email): void
    {
        $audit = new AuditLogger($this->request);
        $email = User::normalizeEmail($email);

        // Counted for every submitted address, existing or not, so the limiter
        // itself does not behave differently for real accounts.
        if (!$this->limiter->attempt('password_forgot', ['ip' => $this->request->ip(), 'account' => $email])) {
            $audit->record('auth.password_reset_throttled', null);

            return;
        }

        $user = $email === '' ? null : $this->users->findByEmail($email);
        // Only active accounts can reset. Invited users finish through their
        // invitation link; blocked or deleted accounts must not be revived here.
        if ($user === null || $user['status'] !== 'active') {
            return;
        }

        $userId = (int) $user['id'];
        ['raw' => $raw, 'hash' => $hash] = $this->tokenService->generate();
        Database::transaction(function () use ($userId, $hash): void {
            // One usable link at a time: an older e-mail stops working.
            $this->tokens->revokeOutstanding($userId);
            $this->tokens->create($userId, $hash, $this->request->ip(), (int) Config::get('security.password_reset_ttl_minutes', 60));
        });
        $audit->record('auth.password_reset_requested', $userId, null, 'user', $userId);

        // Sent after the commit: a slow SMTP server must never hold database locks.
        $this->mailer->sendPasswordReset((string) $user['email'], (string) $user['full_name'], $raw);
    }

    /** State of a raw token for the GET page (valid | expired | invalid). */
    public function inspect(string $rawToken): string
    {
        if (!$this->tokenService->isWellFormed($rawToken)) {
            return self::INVALID;
        }

        return $this->evaluate($this->tokens->findByHash($this->tokenService->hash($rawToken)), $rawToken);
    }

    /**
     * Consumes the token and sets the new password.
     *
     * @return string self::VALID on success, otherwise the reason it failed.
     */
    public function reset(string $rawToken, string $newPassword): string
    {
        if (!$this->tokenService->isWellFormed($rawToken)) {
            return self::INVALID;
        }

        $passwordHash = password_hash(
            $newPassword,
            Config::get('security.password_algo', PASSWORD_DEFAULT),
            Config::get('security.password_options', [])
        );

        return Database::transaction(function () use ($rawToken, $passwordHash): string {
            $token = $this->tokens->findByHash($this->tokenService->hash($rawToken), true);
            $state = $this->evaluate($token, $rawToken);
            if ($state !== self::VALID) {
                return $state;
            }

            $userId = (int) $token['user_id'];
            $user = $this->users->findForUpdate($userId);
            if ($user === null || $user['status'] !== 'active') {
                return self::INVALID;
            }

            $this->tokens->markConsumed((int) $token['id']);
            // updatePassword() increments session_version: every session of this
            // account, including one an attacker may hold, ends on its next request.
            $this->users->updatePassword($userId, $passwordHash);
            // Brief rule: a successful reset invalidates the user's other reset links.
            $this->tokens->revokeOutstanding($userId);
            (new AuditLogger($this->request))->record('auth.password_reset', $userId, null, 'user', $userId);

            return self::VALID;
        });
    }

    /** @param array<string, mixed>|null $token */
    private function evaluate(?array $token, string $rawToken): string
    {
        // hash_equals() as a second, constant-time check after the indexed lookup.
        if ($token === null || !$this->tokenService->matches($rawToken, (string) $token['token_hash'])) {
            return self::INVALID;
        }
        if ($token['consumed_at'] !== null || $token['revoked_at'] !== null) {
            return self::INVALID;
        }

        return (int) $token['is_expired'] === 1 ? self::EXPIRED : self::VALID;
    }
}
