<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Outcome of checking or consuming an e-mail verification token.
 */
final class VerificationResult
{
    /** Token usable: show the confirm button / account activated. */
    public const VALID = 'valid';
    /** Token older than 24 h: offer a new link. */
    public const EXPIRED = 'expired';
    /** Token already consumed or account already verified: send to login. */
    public const ALREADY_USED = 'already_used';
    /** Unknown, malformed or revoked token. */
    public const INVALID = 'invalid';
    /** Invited user must choose a password to activate (no password given). */
    public const PASSWORD_REQUIRED = 'password_required';

    /** @param array<string, mixed>|null $user */
    public function __construct(
        public readonly string $state,
        public readonly ?array $user = null
    ) {
    }

    public function isValid(): bool
    {
        return $this->state === self::VALID;
    }

    /** Invited users have no password yet and choose it on the activation page. */
    public function needsPassword(): bool
    {
        return $this->user !== null && $this->user['password_hash'] === null;
    }
}
