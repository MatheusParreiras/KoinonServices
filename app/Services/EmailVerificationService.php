<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Database;
use App\Core\Request;
use App\Mail\Mailer;
use App\Models\AuditLog;
use App\Models\EmailVerificationToken;
use App\Models\User;

/**
 * Account activation by e-mail (Phase 1, FR-AUTH-12 to FR-AUTH-17).
 *
 *  1. sendNew(): 32 random bytes -> 64 hex chars (TokenService). Only the
 *     SHA-256 hash is stored; the raw token exists only in the e-mailed link.
 *  2. inspect(): used by GET /verify-email to show the right page. Read-only:
 *     link scanners in mail clients may open the link, so GET never consumes.
 *  3. verify(): POST /verify-email. In one transaction, locks the token row,
 *     re-checks it, consumes it, activates the user and revokes other tokens.
 *  4. resend(): throttled (60 s cooldown, 5 per 24 h) and silent, so it reveals
 *     nothing about which e-mails exist.
 */
final class EmailVerificationService
{
    public function __construct(
        private readonly EmailVerificationToken $tokens = new EmailVerificationToken(),
        private readonly User $users = new User(),
        private readonly Mailer $mailer = new Mailer(),
        private readonly AuditLog $audit = new AuditLog(),
        private readonly TokenService $tokenService = new TokenService()
    ) {
    }

    /**
     * Revokes the user's outstanding tokens, creates a new one and e-mails it.
     *
     * @param array<string, mixed> $user
     * @return bool False when the e-mail could not be sent (the user can resend).
     */
    public function sendNew(array $user, string $ip): bool
    {
        $userId = (int) $user['id'];
        ['raw' => $rawToken, 'hash' => $tokenHash] = $this->tokenService->generate();

        Database::transaction(function () use ($userId, $tokenHash, $ip): void {
            $this->tokens->revokeOutstanding($userId);
            $this->tokens->create(
                $userId,
                $tokenHash,
                $ip,
                (int) Config::get('security.verification_ttl_hours', 24)
            );
        });

        // Sent after the commit: a slow SMTP server must never hold database locks.
        return $this->mailer->sendVerification((string) $user['email'], (string) $user['full_name'], $rawToken);
    }

    /**
     * Sends a new link if the account is still unverified and not throttled.
     * Always silent: the caller shows the same message whatever happened.
     */
    public function resend(string $email, Request $request): void
    {
        $user = $this->users->findByEmail($email);
        if ($user === null || $user['status'] !== 'pending_verification') {
            return;
        }

        $userId = (int) $user['id'];
        $sinceLast = $this->tokens->secondsSinceLast($userId);
        $cooldown = (int) Config::get('security.verification_resend_cooldown', 60);
        $maxPerDay = (int) Config::get('security.verification_max_per_day', 5);

        if (($sinceLast !== null && $sinceLast < $cooldown) || $this->tokens->countSince($userId, 24) >= $maxPerDay) {
            $this->audit->record('auth.verification_resend_throttled', $request, $userId);

            return;
        }

        $this->sendNew($user, $request->ip());
        $this->audit->record('auth.verification_resent', $request, $userId);
    }

    /** Read-only check of a raw token (for the GET landing page). */
    public function inspect(string $rawToken): VerificationResult
    {
        if (!$this->tokenService->isWellFormed($rawToken)) {
            return new VerificationResult(VerificationResult::INVALID);
        }

        $token = $this->tokens->findByHash($this->tokenService->hash($rawToken));
        $user = $token === null ? null : $this->users->find((int) $token['user_id']);

        return new VerificationResult($this->evaluate($token, $user), $user);
    }

    /**
     * Consumes the token and activates the account.
     *
     * @param string|null $newPasswordHash Required for invited users (no password yet);
     *                                     must already be validated and hashed.
     */
    public function verify(string $rawToken, ?string $newPasswordHash, Request $request): VerificationResult
    {
        if (!$this->tokenService->isWellFormed($rawToken)) {
            return new VerificationResult(VerificationResult::INVALID);
        }

        return Database::transaction(function () use ($rawToken, $newPasswordHash, $request): VerificationResult {
            // Lock the token, then the user: concurrent submits are serialised,
            // and the second one sees consumed_at set and gets ALREADY_USED.
            $token = $this->tokens->findByHash($this->tokenService->hash($rawToken), true);
            $user = $token === null ? null : $this->users->findForUpdate((int) $token['user_id']);

            $state = $this->evaluate($token, $user);
            if ($state !== VerificationResult::VALID) {
                return new VerificationResult($state, $user);
            }
            if ($user['password_hash'] === null && $newPasswordHash === null) {
                return new VerificationResult(VerificationResult::PASSWORD_REQUIRED, $user);
            }

            $userId = (int) $user['id'];
            $this->tokens->markConsumed((int) $token['id']);
            // Only invited users (no password yet) may set one here; for everyone
            // else a submitted value is ignored, so this page cannot reset passwords.
            $this->users->markEmailVerified($userId, $user['password_hash'] === null ? $newPasswordHash : null);
            $this->tokens->revokeOutstanding($userId);
            $this->audit->record('auth.email_verified', $request, $userId);

            return new VerificationResult(VerificationResult::VALID, $user);
        });
    }

    /**
     * @param array<string, mixed>|null $token
     * @param array<string, mixed>|null $user
     */
    private function evaluate(?array $token, ?array $user): string
    {
        if ($token === null || $user === null) {
            return VerificationResult::INVALID;
        }
        // Checked before "revoked": after activation the other tokens are revoked,
        // and clicking an older link should say "already activated", not "invalid".
        if ($token['consumed_at'] !== null || $user['email_verified_at'] !== null) {
            return VerificationResult::ALREADY_USED;
        }
        if ($token['revoked_at'] !== null || $user['status'] !== 'pending_verification') {
            return VerificationResult::INVALID;
        }
        if ((int) $token['is_expired'] === 1) {
            return VerificationResult::EXPIRED;
        }

        return VerificationResult::VALID;
    }
}
