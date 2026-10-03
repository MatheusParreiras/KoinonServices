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
}
