<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Model;

/**
 * Base class of the per-user token tables that share the Phase 1 design:
 * email_verification_tokens, password_reset_tokens and email_change_tokens.
 *
 * Columns: user_id, token_hash, expires_at, consumed_at, revoked_at,
 * request_ip, created_at. Only hashes are stored (see TokenService). Every time
 * comparison is done by MySQL with UTC_TIMESTAMP(), so expiry does not depend on
 * the PHP server's clock. The table name comes from the subclass (code), never
 * from input.
 */
abstract class UserToken extends Model
{
    /**
     * Finds a token by hash. `is_expired` is computed by MySQL. With $forUpdate
     * the row stays locked until the transaction ends, so two simultaneous
     * submits cannot both consume the same token.
     *
     * @return array<string, mixed>|null
     */
    public function findByHash(string $tokenHash, bool $forUpdate = false): ?array
    {
        $sql = sprintf(
            'SELECT t.*, (t.expires_at <= UTC_TIMESTAMP()) AS is_expired FROM `%s` t WHERE t.token_hash = :token_hash%s',
            $this->table,
            $forUpdate ? ' FOR UPDATE' : ''
        );

        return $this->fetchOne($sql, ['token_hash' => $tokenHash]);
    }

    /** Marks a token as used (single use). */
    public function markConsumed(int $id): void
    {
        $this->execute(
            sprintf('UPDATE `%s` SET consumed_at = UTC_TIMESTAMP() WHERE id = :id AND consumed_at IS NULL', $this->table),
            ['id' => $id]
        );
    }

    /**
     * Revokes every still-usable token of a user: on resend, after use, and
     * after a password change (Phase 5 rule: a reset invalidates the others).
     */
    public function revokeOutstanding(int $userId): void
    {
        $this->execute(
            sprintf(
                'UPDATE `%s` SET revoked_at = UTC_TIMESTAMP()
                  WHERE user_id = :user_id AND consumed_at IS NULL AND revoked_at IS NULL',
                $this->table
            ),
            ['user_id' => $userId]
        );
    }

    /** Seconds since the user's most recent token, or null if there is none. */
    public function secondsSinceLast(int $userId): ?int
    {
        $row = $this->fetchOne(
            sprintf(
                'SELECT TIMESTAMPDIFF(SECOND, MAX(created_at), UTC_TIMESTAMP()) AS seconds FROM `%s` WHERE user_id = :user_id',
                $this->table
            ),
            ['user_id' => $userId]
        );

        return ($row === null || $row['seconds'] === null) ? null : (int) $row['seconds'];
    }

    /** Number of tokens created for the user in the last $hours hours. */
    public function countSince(int $userId, int $hours): int
    {
        $row = $this->fetchOne(
            sprintf(
                'SELECT COUNT(*) AS total FROM `%s`
                  WHERE user_id = :user_id AND created_at > UTC_TIMESTAMP() - INTERVAL :hours HOUR',
                $this->table
            ),
            ['user_id' => $userId, 'hours' => $hours]
        );

        return (int) ($row['total'] ?? 0);
    }

    /**
     * Stores a new token hash valid for $ttlMinutes.
     *
     * @param array<string, string> $extra Additional columns of the subclass
     *                                     (keys from code, values bound).
     */
    protected function insertToken(int $userId, string $tokenHash, string $ip, int $ttlMinutes, array $extra = []): void
    {
        $columns = '';
        $values = '';
        foreach (array_keys($extra) as $column) {
            $columns .= ', `' . $column . '`';
            $values .= ', :' . $column;
        }

        $this->execute(
            sprintf(
                'INSERT INTO `%s` (user_id, token_hash, expires_at, request_ip%s)
                 VALUES (:user_id, :token_hash, UTC_TIMESTAMP() + INTERVAL :ttl MINUTE, INET6_ATON(:ip)%s)',
                $this->table,
                $columns,
                $values
            ),
            ['user_id' => $userId, 'token_hash' => $tokenHash, 'ttl' => $ttlMinutes, 'ip' => $ip] + $extra
        );
    }
}
