<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Activation tokens `email_verification_tokens` (global: they belong to a user,
 * not to a tenant).
 *
 * Only SHA-256 hashes are stored. A database leak therefore does not reveal any
 * usable activation link. Lookup, consumption, revocation and throttling
 * queries are shared with the other token tables (UserToken, Phase 5).
 */
final class EmailVerificationToken extends UserToken
{
    protected string $table = 'email_verification_tokens';

    /** Stores a new token hash valid for $ttlHours. */
    public function create(int $userId, string $tokenHash, string $ip, int $ttlHours): void
    {
        $this->insertToken($userId, $tokenHash, $ip, $ttlHours * 60);
    }
}
