<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Model;

/**
 * Activation tokens `email_verification_tokens` (global: they belong to a user,
 * not to a tenant).
 *
 * Only SHA-256 hashes are stored. A database leak therefore does not reveal any
 * usable activation link. Times are computed by MySQL (UTC_TIMESTAMP()) so that
 * expiry never depends on the PHP server's clock.
 */
final class EmailVerificationToken extends Model
{
    protected string $table = 'email_verification_tokens';

    /** Stores a new token hash valid for $ttlHours. */
    public function create(int $userId, string $tokenHash, string $ip, int $ttlHours): void
    {
        $this->execute(
            'INSERT INTO email_verification_tokens (user_id, token_hash, expires_at, request_ip)
             VALUES (:user_id, :token_hash, UTC_TIMESTAMP() + INTERVAL :ttl HOUR, INET6_ATON(:ip))',
            ['user_id' => $userId, 'token_hash' => $tokenHash, 'ttl' => $ttlHours, 'ip' => $ip]
        );
    }

    /**
     * Finds a token by hash. `is_expired` is computed by MySQL. With $forUpdate
     * the row stays locked until the transaction ends, so two simultaneous
     * clicks cannot both consume the same token.
     */
    public function findByHash(string $tokenHash, bool $forUpdate = false): ?array
    {
        $sql = 'SELECT id, user_id, consumed_at, revoked_at,
                       (expires_at <= UTC_TIMESTAMP()) AS is_expired
                  FROM email_verification_tokens
                 WHERE token_hash = :token_hash'
            . ($forUpdate ? ' FOR UPDATE' : '');

        return $this->fetchOne($sql, ['token_hash' => $tokenHash]);
    }

    /** Marks a token as used (single use). */
    public function markConsumed(int $id): void
    {
        $this->execute(
            'UPDATE email_verification_tokens SET consumed_at = UTC_TIMESTAMP() WHERE id = :id',
            ['id' => $id]
        );
    }

    /** Revokes every still-usable token of a user (on resend and after activation). */
    public function revokeOutstanding(int $userId): void
    {
        $this->execute(
            'UPDATE email_verification_tokens
                SET revoked_at = UTC_TIMESTAMP()
              WHERE user_id = :user_id AND consumed_at IS NULL AND revoked_at IS NULL',
            ['user_id' => $userId]
        );
    }

    /** Seconds since the user's most recent token, or null if there is none. */
    public function secondsSinceLast(int $userId): ?int
    {
        $row = $this->fetchOne(
            'SELECT TIMESTAMPDIFF(SECOND, MAX(created_at), UTC_TIMESTAMP()) AS seconds
               FROM email_verification_tokens WHERE user_id = :user_id',
            ['user_id' => $userId]
        );

        return ($row === null || $row['seconds'] === null) ? null : (int) $row['seconds'];
    }

    /** Number of tokens created for the user in the last $hours hours. */
    public function countSince(int $userId, int $hours): int
    {
        $row = $this->fetchOne(
            'SELECT COUNT(*) AS total FROM email_verification_tokens
              WHERE user_id = :user_id AND created_at > UTC_TIMESTAMP() - INTERVAL :hours HOUR',
            ['user_id' => $userId, 'hours' => $hours]
        );

        return (int) ($row['total'] ?? 0);
    }
}
