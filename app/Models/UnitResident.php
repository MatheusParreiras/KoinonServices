<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\TenantContext;
use App\Core\TenantModel;

/**
 * Links between members and units (`unit_residents`).
 *
 * This model answers the Resident ownership question used across modules:
 * "which units does this user currently live in?". Every module filters a
 * resident's data by these unit ids (or by their own user id).
 */
final class UnitResident extends TenantModel
{
    protected string $table = 'unit_residents';

    /**
     * Ids of the units the user is currently linked to in the current tenant.
     *
     * @param bool $billingOnly Only owner/tenant links: dependents do not see the
     *                          unit's bills (Phase 1, FR-FIN-10).
     * @return list<int>
     */
    public function activeUnitIds(int $userId, bool $billingOnly = false): array
    {
        $sql = 'SELECT unit_id
                  FROM unit_residents
                 WHERE condominium_id = :tenant          -- tenant scope
                   AND user_id = :user_id                -- only the links of THIS user
                   AND (move_out_date IS NULL OR move_out_date >= :today)';
        if ($billingOnly) {
            $sql .= " AND relationship IN ('owner', 'tenant')";
        }

        $rows = $this->fetchAll($sql, $this->scoped(['user_id' => $userId, 'today' => TenantContext::today()]));

        return array_map(static fn (array $row): int => (int) $row['unit_id'], $rows);
    }

    /**
     * E-mail contacts of the people currently living in a unit (active accounts
     * and memberships only). Used to notify residents about a package.
     *
     * @return list<array{email: string, full_name: string}>
     */
    public function contactsForUnit(int $unitId): array
    {
        return $this->fetchAll(
            "SELECT us.email, us.full_name
               FROM unit_residents ur
               JOIN condominium_users cu
                 ON cu.condominium_id = ur.condominium_id AND cu.user_id = ur.user_id AND cu.status = 'active'
               JOIN users us ON us.id = ur.user_id AND us.status = 'active'
              WHERE ur.condominium_id = :tenant
                AND ur.unit_id = :unit_id
                AND (ur.move_out_date IS NULL OR ur.move_out_date >= :today)",
            $this->scoped(['unit_id' => $unitId, 'today' => TenantContext::today()])
        );
    }

    /**
     * Makes $unitId the user's only current unit in this condominium (Phase 5,
     * user management). Other current links get move_out_date = today (rows are
     * kept for history); an earlier link to the same unit is re-opened instead
     * of duplicated (UNIQUE condominium_id, unit_id, user_id). $unitId null
     * just closes every current link.
     *
     * The unit must already have been checked to belong to the tenant
     * (Unit::findActive); the composite FK would reject it otherwise anyway.
     */
    public function assign(int $userId, ?int $unitId, string $relationship): void
    {
        $today = TenantContext::today();
        $this->execute(
            'UPDATE unit_residents
                SET move_out_date = :today
              WHERE condominium_id = :tenant AND user_id = :user_id
                AND move_out_date IS NULL
                AND (:unit_id IS NULL OR unit_id <> :unit_id_b)',
            $this->scoped(['today' => $today, 'user_id' => $userId, 'unit_id' => $unitId, 'unit_id_b' => $unitId])
        );
        if ($unitId === null) {
            return;
        }

        $this->execute(
            'INSERT INTO unit_residents (condominium_id, unit_id, user_id, relationship, is_billing_contact, move_in_date)
             VALUES (:tenant, :unit_id, :user_id, :relationship, :billing, :today)
             ON DUPLICATE KEY UPDATE relationship = VALUES(relationship),
                                     is_billing_contact = VALUES(is_billing_contact),
                                     move_out_date = NULL',
            $this->scoped([
                'unit_id'      => $unitId,
                'user_id'      => $userId,
                'relationship' => $relationship,
                'billing'      => $relationship === 'dependent' ? 0 : 1,
                'today'        => $today,
            ])
        );
    }

    /**
     * Current unit and relationship of each given member (one unit shown per
     * person in the admin list; the most recent link wins).
     *
     * @param list<int> $userIds
     * @return array<int, array{unit_id: int, unit_label: string, relationship: string}>
     */
    public function currentUnits(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }
        [$placeholders, $params] = $this->inList('user', $userIds);
        $rows = $this->fetchAll(
            "SELECT ur.user_id, ur.unit_id, ur.relationship, " . Unit::LABEL_SQL . " AS unit_label
               FROM unit_residents ur
               JOIN units u ON u.condominium_id = ur.condominium_id AND u.id = ur.unit_id
              WHERE ur.condominium_id = :tenant
                AND ur.user_id IN ({$placeholders})
                AND (ur.move_out_date IS NULL OR ur.move_out_date >= :today)
              ORDER BY ur.id",
            $this->scoped(['today' => TenantContext::today()] + $params)
        );

        $byUser = [];
        foreach ($rows as $row) {
            $byUser[(int) $row['user_id']] = [
                'unit_id'      => (int) $row['unit_id'],
                'unit_label'   => (string) $row['unit_label'],
                'relationship' => (string) $row['relationship'],
            ];
        }

        return $byUser;
    }
}
