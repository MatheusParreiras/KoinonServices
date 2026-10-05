<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Model;

/**
 * Invitation tokens `invitations` (migration 0003).
 *
 * This model extends the global Model, not TenantModel, on purpose: invitations
 * are created both by a Property Manager (tenant = the session's condominium)
 * and by the Super Admin (tenant = a condominium chosen on the platform screen,
 * validated to exist), and they are accepted by a logged-out invitee, when no
 * TenantContext exists. Every method therefore takes the condominium id
 * explicitly, and InvitationService documents where each caller's id comes from.
 */
final class Invitation extends Model
{
    protected string $table = 'invitations';

    /** Stores a new invitation token valid for $ttlHours. */
    public function create(int $condominiumId, int $userId, string $tokenHash, int $invitedBy, string $ip, int $ttlHours): int
    {
        $this->execute(
            'INSERT INTO invitations (condominium_id, user_id, token_hash, expires_at, invited_by_user_id, request_ip)
             VALUES (:condominium_id, :user_id, :token_hash, UTC_TIMESTAMP() + INTERVAL :ttl HOUR, :invited_by, INET6_ATON(:ip))',
            [
                'condominium_id' => $condominiumId,
                'user_id'        => $userId,
                'token_hash'     => $tokenHash,
                'ttl'            => $ttlHours,
                'invited_by'     => $invitedBy,
                'ip'             => $ip,
            ]
        );

        return (int) $this->db()->lastInsertId();
    }

    /**
     * Finds an invitation by token hash, with what the landing page needs
     * (condominium, invitee, membership state). Read-only.
     *
     * @return array<string, mixed>|null
     */
    public function findByHash(string $tokenHash): ?array
    {
        return $this->fetchOne(
            'SELECT i.*, (i.expires_at <= UTC_TIMESTAMP()) AS is_expired,
                    c.name AS condominium_name, c.status AS condominium_status,
                    u.email, u.full_name, u.status AS user_status, (u.password_hash IS NULL) AS needs_password,
                    cu.status AS membership_status, r.code AS role_code
               FROM invitations i
               JOIN condominiums c       ON c.id = i.condominium_id
               JOIN users u              ON u.id = i.user_id
               JOIN condominium_users cu ON cu.condominium_id = i.condominium_id AND cu.user_id = i.user_id
               JOIN roles r              ON r.id = cu.role_id
              WHERE i.token_hash = :token_hash',
            ['token_hash' => $tokenHash]
        );
    }

    /**
     * Locks ONLY the invitation row (no join, so no lock is taken on the shared
     * roles/condominiums rows) until the transaction ends. Two simultaneous
     * "accept" clicks are serialised here and the second sees consumed_at set.
     *
     * @return array<string, mixed>|null
     */
    public function lockByHash(string $tokenHash): ?array
    {
        return $this->fetchOne(
            'SELECT i.*, (i.expires_at <= UTC_TIMESTAMP()) AS is_expired
               FROM invitations i
              WHERE i.token_hash = :token_hash
              FOR UPDATE',
            ['token_hash' => $tokenHash]
        );
    }

    /**
     * Latest invitation of each of the given memberships (for the user list:
     * "pending" vs "expired" badge).
     *
     * @param list<int> $userIds
     * @return array<int, array{expires_at: string, is_expired: int}> keyed by user id
     */
    public function latestForUsers(int $condominiumId, array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }
        [$placeholders, $params] = $this->inList('user', $userIds);
        $rows = $this->fetchAll(
            "SELECT i.user_id, i.expires_at, (i.expires_at <= UTC_TIMESTAMP()) AS is_expired
               FROM invitations i
               JOIN (SELECT user_id, MAX(id) AS last_id
                       FROM invitations
                      WHERE condominium_id = :condominium_a AND user_id IN ({$placeholders})
                      GROUP BY user_id) latest ON latest.last_id = i.id
              WHERE i.condominium_id = :condominium_b",
            ['condominium_a' => $condominiumId, 'condominium_b' => $condominiumId] + $params
        );

        $byUser = [];
        foreach ($rows as $row) {
            $byUser[(int) $row['user_id']] = ['expires_at' => (string) $row['expires_at'], 'is_expired' => (int) $row['is_expired']];
        }

        return $byUser;
    }

    /** Marks an invitation as accepted (single use). */
    public function markConsumed(int $id): void
    {
        $this->execute(
            'UPDATE invitations SET consumed_at = UTC_TIMESTAMP() WHERE id = :id AND consumed_at IS NULL',
            ['id' => $id]
        );
    }

    /** Revokes the still-usable invitations of one membership (resend, cancel, accept). */
    public function revokeOutstanding(int $condominiumId, int $userId): void
    {
        $this->execute(
            'UPDATE invitations SET revoked_at = UTC_TIMESTAMP()
              WHERE condominium_id = :condominium_id AND user_id = :user_id
                AND consumed_at IS NULL AND revoked_at IS NULL',
            ['condominium_id' => $condominiumId, 'user_id' => $userId]
        );
    }

    /** Seconds since the membership's latest invitation, or null. */
    public function secondsSinceLast(int $condominiumId, int $userId): ?int
    {
        $row = $this->fetchOne(
            'SELECT TIMESTAMPDIFF(SECOND, MAX(created_at), UTC_TIMESTAMP()) AS seconds
               FROM invitations WHERE condominium_id = :condominium_id AND user_id = :user_id',
            ['condominium_id' => $condominiumId, 'user_id' => $userId]
        );

        return ($row === null || $row['seconds'] === null) ? null : (int) $row['seconds'];
    }

    /** Invitations e-mailed for this membership in the last $hours hours. */
    public function countSince(int $condominiumId, int $userId, int $hours): int
    {
        $row = $this->fetchOne(
            'SELECT COUNT(*) AS total FROM invitations
              WHERE condominium_id = :condominium_id AND user_id = :user_id
                AND created_at > UTC_TIMESTAMP() - INTERVAL :hours HOUR',
            ['condominium_id' => $condominiumId, 'user_id' => $userId, 'hours' => $hours]
        );

        return (int) ($row['total'] ?? 0);
    }
}
