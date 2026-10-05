<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Model;

/**
 * Memberships `condominium_users`: which user belongs to which condominium, with
 * which role.
 *
 * Although the table has a condominium_id column, this model extends the global
 * Model on purpose. It is the bridge used to DECIDE the tenant (at login and in
 * TenantMiddleware), so it runs before a TenantContext exists. Every query is
 * filtered by user_id instead: a user can only ever see their own memberships.
 */
final class Membership extends Model
{
    protected string $table = 'condominium_users';

    protected array $fillable = [
        'condominium_id',
        'user_id',
        'role_id',
        'status',
        'approved_by_user_id',
        'approved_at',
    ];

    private const ACTIVE_SELECT = <<<'SQL'
        SELECT cu.condominium_id,
               cu.role_id,
               r.code AS role_code,
               r.name AS role_name,
               c.name AS condominium_name,
               c.city AS condominium_city,
               c.timezone AS condominium_timezone
          FROM condominium_users cu
          JOIN roles r        ON r.id = cu.role_id
          JOIN condominiums c ON c.id = cu.condominium_id
         WHERE cu.user_id = :user_id
           AND cu.status = 'active'
           AND c.status = 'active'
        SQL;

    /**
     * Active memberships of a user in active condominiums.
     *
     * @return list<array<string, mixed>>
     */
    public function activeForUser(int $userId): array
    {
        return $this->fetchAll(self::ACTIVE_SELECT . ' ORDER BY c.name', ['user_id' => $userId]);
    }

    /** One active membership of the user in the given condominium, or null. */
    public function findActive(int $userId, int $condominiumId): ?array
    {
        return $this->fetchOne(
            self::ACTIVE_SELECT . ' AND cu.condominium_id = :condominium_id LIMIT 1',
            ['user_id' => $userId, 'condominium_id' => $condominiumId]
        );
    }

    /**
     * True when the user can still enter at least one condominium (active
     * membership in an active condominium). Checked on every request by Auth.
     */
    public function hasAnyActive(int $userId): bool
    {
        return $this->fetchOne(self::ACTIVE_SELECT . ' LIMIT 1', ['user_id' => $userId]) !== null;
    }

    /** True when the user has any membership (any status) in the condominium. */
    public function exists(int $userId, int $condominiumId): bool
    {
        return $this->fetchOne(
            'SELECT 1 FROM condominium_users WHERE user_id = :user_id AND condominium_id = :condominium_id',
            ['user_id' => $userId, 'condominium_id' => $condominiumId]
        ) !== null;
    }

    /**
     * Creates a membership in status 'invited' (Phase 5). It grants nothing
     * until accepted: every access query requires status = 'active'.
     */
    public function createInvited(int $condominiumId, int $userId, int $roleId): void
    {
        $this->execute(
            "INSERT INTO condominium_users (condominium_id, user_id, role_id, status)
             VALUES (:condominium_id, :user_id, :role_id, 'invited')",
            ['condominium_id' => $condominiumId, 'user_id' => $userId, 'role_id' => $roleId]
        );
    }

    /**
     * Locks one membership row for the rest of the transaction.
     *
     * @return array<string, mixed>|null
     */
    public function lockFor(int $condominiumId, int $userId): ?array
    {
        return $this->fetchOne(
            'SELECT * FROM condominium_users
              WHERE condominium_id = :condominium_id AND user_id = :user_id
              FOR UPDATE',
            ['condominium_id' => $condominiumId, 'user_id' => $userId]
        );
    }

    /** Turns an accepted invitation into an active membership. */
    public function activateInvited(int $condominiumId, int $userId, int $approvedBy): void
    {
        $this->execute(
            "UPDATE condominium_users
                SET status = 'active', approved_at = UTC_TIMESTAMP(), approved_by_user_id = :approved_by,
                    deactivated_at = NULL, deactivated_by_user_id = NULL
              WHERE condominium_id = :condominium_id AND user_id = :user_id AND status = 'invited'",
            ['approved_by' => $approvedBy, 'condominium_id' => $condominiumId, 'user_id' => $userId]
        );
    }
}
