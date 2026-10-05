<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Password reset tokens `password_reset_tokens` (Phase 1 table, 60-minute lifetime
 * by default). Same design as the verification tokens: hash only, single use.
 */
final class PasswordResetToken extends UserToken
{
    protected string $table = 'password_reset_tokens';

    /** Stores a new token hash valid for $ttlMinutes. */
    public function create(int $userId, string $tokenHash, string $ip, int $ttlMinutes): void
    {
        $this->insertToken($userId, $tokenHash, $ip, $ttlMinutes);
    }
}
