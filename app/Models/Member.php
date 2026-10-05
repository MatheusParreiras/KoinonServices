<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Pagination;
use App\Core\TenantModel;

/**
 * The current condominium's members, as seen by its Property Manager (Phase 5).
 *
 * Same table as Membership (`condominium_users`), different job:
 *  - Membership (global Model) decides WHICH tenant a user may enter, filtered by
 *    user_id, before a TenantContext exists.
 *  - Member (TenantModel) lists and edits the people of ONE tenant. Every query
 *    is "condominium_id = :tenant", so a user id from another condominium is
 *    simply not found and the controller answers 404.
 *
 * Global identity columns (users.full_name, users.email, password) are only
 * read here, never written: a user may belong to several condominiums, and a
 * manager of one must not be able to change an account that another uses.
 */
final class Member extends TenantModel
{
    public const STATUS_FILTERS = ['active', 'inactive', 'invited'];

    protected string $table = 'condominium_users';

    /**
     * One page of members matching the filters.
     *
     * @param array{q?: string, role?: string, unit_id?: int, status?: string} $filters Already validated.
     * @return list<array<string, mixed>>
     */
    public function search(array $filters, Pagination $pagination): array
    {
        [$where, $params] = $this->filterSql($filters);

        return $this->fetchAll(
            "SELECT cu.user_id, cu.status, cu.created_at, cu.approved_at, cu.deactivated_at,
                    r.code AS role_code, us.full_name, us.email, us.status AS user_status, us.last_login_at
               FROM condominium_users cu
               JOIN users us ON us.id = cu.user_id
               JOIN roles r  ON r.id = cu.role_id
              WHERE {$where}
              ORDER BY cu.status = 'inactive', us.full_name, cu.user_id
              LIMIT :limit OFFSET :offset",
            $params + ['limit' => $pagination->limit(), 'offset' => $pagination->offset()]
        );
    }

    /** @param array{q?: string, role?: string, unit_id?: int, status?: string} $filters */
    public function count(array $filters): int
    {
        [$where, $params] = $this->filterSql($filters);
        $row = $this->fetchOne(
            "SELECT COUNT(*) AS total
               FROM condominium_users cu
               JOIN users us ON us.id = cu.user_id
               JOIN roles r  ON r.id = cu.role_id
              WHERE {$where}",
            $params
        );

        return (int) ($row['total'] ?? 0);
    }

    /**
     * One member of the current condominium by USER id, or null (also for users
     * who belong only to other condominiums: same 404 as a missing id).
     *
     * @return array<string, mixed>|null
     */
    public function findByUser(int $userId): ?array
    {
        return $this->fetchOne(
            'SELECT cu.*, r.code AS role_code, us.full_name, us.email, us.status AS user_status
               FROM condominium_users cu
               JOIN users us ON us.id = cu.user_id
               JOIN roles r  ON r.id = cu.role_id
              WHERE cu.condominium_id = :tenant AND cu.user_id = :user_id',
            $this->scoped(['user_id' => $userId])
        );
    }

    /**
     * Locks the membership row (no joins, so the shared roles/users rows are not
     * locked) and returns it with its role code.
     *
     * @return array<string, mixed>|null
     */
    public function lockByUser(int $userId): ?array
    {
        $row = $this->fetchOne(
            'SELECT * FROM condominium_users WHERE condominium_id = :tenant AND user_id = :user_id FOR UPDATE',
            $this->scoped(['user_id' => $userId])
        );
        if ($row === null) {
            return null;
        }
        $row['role_code'] = (new Role())->codeById((int) $row['role_id']);

        return $row;
    }

    /**
     * Locks every ACTIVE manager membership of the tenant and returns how many
     * there are.
     *
     * The "last active Property Manager" rule is checked with these rows locked:
     * two managers deactivating each other at the same moment are serialised,
     * and the second one sees that only one manager is left.
     */
    public function lockActiveManagerCount(int $managerRoleId): int
    {
        $rows = $this->fetchAll(
            "SELECT id FROM condominium_users
              WHERE condominium_id = :tenant AND role_id = :role_id AND status = 'active'
              FOR UPDATE",
            $this->scoped(['role_id' => $managerRoleId])
        );

        return count($rows);
    }

    public function changeRole(int $userId, int $roleId): void
    {
        $this->execute(
            'UPDATE condominium_users SET role_id = :role_id WHERE condominium_id = :tenant AND user_id = :user_id',
            $this->scoped(['role_id' => $roleId, 'user_id' => $userId])
        );
    }

    /** Deactivation keeps the row (history: posts, invoices, occurrences reference it). */
    public function deactivate(int $userId, int $actorId): void
    {
        $this->execute(
            "UPDATE condominium_users
                SET status = 'inactive', deactivated_at = UTC_TIMESTAMP(), deactivated_by_user_id = :actor
              WHERE condominium_id = :tenant AND user_id = :user_id",
            $this->scoped(['actor' => $actorId, 'user_id' => $userId])
        );
    }

    /**
     * Reactivates a membership. An account that never accepted its invitation
     * (no verified e-mail yet) goes back to 'invited' instead of 'active', so it
     * still needs an invitation link to get in.
     */
    public function reactivate(int $userId, int $actorId, bool $accountReady): void
    {
        $this->execute(
            "UPDATE condominium_users
                SET status = IF(:ready = 1, 'active', 'invited'),
                    approved_at = COALESCE(approved_at, UTC_TIMESTAMP()),
                    approved_by_user_id = COALESCE(approved_by_user_id, :actor),
                    deactivated_at = NULL,
                    deactivated_by_user_id = NULL
              WHERE condominium_id = :tenant AND user_id = :user_id",
            $this->scoped(['ready' => $accountReady ? 1 : 0, 'actor' => $actorId, 'user_id' => $userId])
        );
    }

    /**
     * Active member counts by role for the current tenant (admin overview).
     *
     * @return array<string, int>
     */
    public function activeCountsByRole(): array
    {
        $rows = $this->fetchAll(
            "SELECT r.code, COUNT(*) AS total
               FROM condominium_users cu JOIN roles r ON r.id = cu.role_id
              WHERE cu.condominium_id = :tenant AND cu.status = 'active'
              GROUP BY r.code",
            $this->scoped()
        );

        $counts = [];
        foreach ($rows as $row) {
            $counts[(string) $row['code']] = (int) $row['total'];
        }

        return $counts;
    }

    /**
     * WHERE clause from fixed fragments; values are bound.
     *
     * @param array<string, mixed> $filters
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function filterSql(array $filters): array
    {
        $conditions = ['cu.condominium_id = :tenant'];   // tenant scope, always first
        $params = [];

        if (isset($filters['q']) && $filters['q'] !== '') {
            $conditions[] = '(us.full_name LIKE :q_name OR us.email LIKE :q_email)';
            $params['q_name'] = self::likeContains($filters['q']);
            $params['q_email'] = self::likeContains($filters['q']);
        }
        if (isset($filters['role'])) {
            $conditions[] = 'r.code = :role';
            $params['role'] = $filters['role'];
        }
        if (isset($filters['status'])) {
            $conditions[] = 'cu.status = :status';
            $params['status'] = $filters['status'];
        }
        if (isset($filters['unit_id'])) {
            // The unit filter is itself tenant-scoped: a unit id of another
            // condominium matches no unit_residents row here.
            $conditions[] = 'EXISTS (SELECT 1 FROM unit_residents ur
                                      WHERE ur.condominium_id = cu.condominium_id
                                        AND ur.user_id = cu.user_id
                                        AND ur.unit_id = :unit_id
                                        AND ur.move_out_date IS NULL)';
            $params['unit_id'] = $filters['unit_id'];
        }

        return [implode(' AND ', $conditions), $this->scoped($params)];
    }
}
