<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Pending e-mail address changes `email_change_tokens` (migration 0003).
 * The new address waits here until its owner confirms it through the link.
 */
final class EmailChangeToken extends UserToken
{
    protected string $table = 'email_change_tokens';

    /** Stores a new token hash for $newEmail (already normalised), valid for $ttlMinutes. */
    public function create(int $userId, string $newEmail, string $tokenHash, string $ip, int $ttlMinutes): void
    {
        $this->insertToken($userId, $tokenHash, $ip, $ttlMinutes, ['new_email' => $newEmail]);
    }
}
