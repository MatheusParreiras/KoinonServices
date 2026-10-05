<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Model;
use App\Core\Pagination;
use InvalidArgumentException;

/**
 * The tenant registry `condominiums` (global table, managed by the Super Admin).
 *
 * Phase 5 adds the platform queries. They are deliberately NOT tenant-scoped:
 * only routes behind the "platform" middleware (Super Admin) call them.
 */
final class Condominium extends Model
{
    public const STATUSES = ['active' => 'Ativo', 'suspended' => 'Suspenso', 'archived' => 'Arquivado'];

    public const PLANS = ['trial' => 'Avaliação', 'basic' => 'Básico', 'professional' => 'Profissional'];

    protected string $table = 'condominiums';

    protected array $fillable = [
        'name',
        'slug',
        'legal_id',
        'signup_code',
        'email',
        'contact_name',
        'phone',
        'address_line',
        'city',
        'state_province',
        'postal_code',
        'timezone',
        'billing_due_day',
        'plan',
    ];

    /** Returns the condominium only if its status is 'active'. */
    public function findActive(int $id): ?array
    {
        return $this->fetchOne(
            "SELECT id, name, timezone FROM condominiums WHERE id = :id AND status = 'active'",
            ['id' => $id]
        );
    }

    /**
     * Every active condominium, for the Super Admin's selector.
     *
     * @return list<array{id: int, name: string, city: string}>
     */
    public function allActive(): array
    {
        return $this->fetchAll(
            "SELECT id, name, city FROM condominiums WHERE status = 'active' ORDER BY name"
        );
    }

    /**
     * One page of condominiums with member statistics.
     *
     * @param array{q?: string, status?: string} $filters Already validated.
     * @return list<array<string, mixed>>
     */
    public function search(array $filters, Pagination $pagination): array
    {
        [$where, $params] = $this->filterSql($filters);

        return $this->fetchAll(
            "SELECT c.id, c.name, c.city, c.state_province, c.legal_id, c.plan, c.status, c.created_at,
                    COALESCE(SUM(cu.status = 'active'), 0) AS active_members,
                    COALESCE(SUM(cu.status = 'invited'), 0) AS invited_members,
                    COALESCE(SUM(cu.status = 'active' AND r.code = 'manager'), 0) AS active_managers
               FROM condominiums c
               LEFT JOIN condominium_users cu ON cu.condominium_id = c.id
               LEFT JOIN roles r ON r.id = cu.role_id
              WHERE {$where}
              GROUP BY c.id
              ORDER BY c.name
              LIMIT :limit OFFSET :offset",
            $params + ['limit' => $pagination->limit(), 'offset' => $pagination->offset()]
        );
    }

    /**
     * The condominiums with the most active members (platform overview).
     *
     * @return list<array<string, mixed>>
     */
    public function largest(int $limit): array
    {
        return $this->fetchAll(
            "SELECT c.id, c.name, c.city, c.status,
                    COALESCE(SUM(cu.status = 'active'), 0) AS active_members,
                    COALESCE(SUM(cu.status = 'invited'), 0) AS invited_members,
                    COALESCE(SUM(cu.status = 'active' AND r.code = 'manager'), 0) AS active_managers
               FROM condominiums c
               LEFT JOIN condominium_users cu ON cu.condominium_id = c.id
               LEFT JOIN roles r ON r.id = cu.role_id
              GROUP BY c.id
              ORDER BY active_members DESC, c.name
              LIMIT :limit",
            ['limit' => $limit]
        );
    }

    /**
     * Every condominium as id => name, for the audit filter.
     *
     * @return array<int, string>
     */
    public function options(): array
    {
        $options = [];
        foreach ($this->fetchAll('SELECT id, name FROM condominiums ORDER BY name') as $row) {
            $options[(int) $row['id']] = (string) $row['name'];
        }

        return $options;
    }

    /** @param array{q?: string, status?: string} $filters */
    public function count(array $filters): int
    {
        [$where, $params] = $this->filterSql($filters);
        $row = $this->fetchOne("SELECT COUNT(*) AS total FROM condominiums c WHERE {$where}", $params);

        return (int) ($row['total'] ?? 0);
    }

    /**
     * Number of condominiums per status (platform overview).
     *
     * @return array<string, int>
     */
    public function countsByStatus(): array
    {
        $counts = array_fill_keys(array_keys(self::STATUSES), 0);
        foreach ($this->fetchAll('SELECT status, COUNT(*) AS total FROM condominiums GROUP BY status') as $row) {
            $counts[(string) $row['status']] = (int) $row['total'];
        }

        return $counts;
    }

    /**
     * Managers of one condominium (any membership status) with their latest
     * invitation, for the Super Admin's condominium page.
     *
     * @return list<array<string, mixed>>
     */
    public function managers(int $condominiumId): array
    {
        return $this->fetchAll(
            "SELECT us.id AS user_id, us.full_name, us.email, cu.status, cu.created_at,
                    (SELECT MAX(i.expires_at) FROM invitations i
                      WHERE i.condominium_id = cu.condominium_id AND i.user_id = cu.user_id
                        AND i.consumed_at IS NULL AND i.revoked_at IS NULL) AS invitation_expires_at,
                    (SELECT MAX(i.expires_at) <= UTC_TIMESTAMP() FROM invitations i
                      WHERE i.condominium_id = cu.condominium_id AND i.user_id = cu.user_id
                        AND i.consumed_at IS NULL AND i.revoked_at IS NULL) AS invitation_expired
               FROM condominium_users cu
               JOIN users us ON us.id = cu.user_id
               JOIN roles r  ON r.id = cu.role_id AND r.code = 'manager'
              WHERE cu.condominium_id = :condominium_id
              ORDER BY cu.status = 'active' DESC, us.full_name",
            ['condominium_id' => $condominiumId]
        );
    }

    /** True when $column = $value is used by another condominium (unique fields). */
    public function isTaken(string $column, string $value, ?int $exceptId = null): bool
    {
        if (!in_array($column, ['slug', 'legal_id', 'signup_code'], true)) {
            throw new InvalidArgumentException('Not a unique column of condominiums.');
        }

        return $this->fetchOne(
            sprintf('SELECT 1 FROM condominiums WHERE `%s` = :value AND id <> :except LIMIT 1', $column),
            ['value' => $value, 'except' => $exceptId ?? 0]
        ) !== null;
    }

    public function suspend(int $id, string $reason): void
    {
        $this->execute(
            "UPDATE condominiums SET status = 'suspended', suspended_at = UTC_TIMESTAMP(), suspension_reason = :reason
              WHERE id = :id AND status = 'active'",
            ['reason' => $reason, 'id' => $id]
        );
    }

    public function reactivate(int $id): void
    {
        $this->execute(
            "UPDATE condominiums SET status = 'active', suspended_at = NULL, suspension_reason = NULL
              WHERE id = :id AND status = 'suspended'",
            ['id' => $id]
        );
    }

    /**
     * Locks a condominium row for a status change.
     *
     * @return array<string, mixed>|null
     */
    public function lockForUpdate(int $id): ?array
    {
        return $this->fetchOne('SELECT * FROM condominiums WHERE id = :id FOR UPDATE', ['id' => $id]);
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function filterSql(array $filters): array
    {
        $conditions = ['1 = 1'];
        $params = [];
        if (isset($filters['q']) && $filters['q'] !== '') {
            $conditions[] = '(c.name LIKE :q_name OR c.city LIKE :q_city OR c.legal_id LIKE :q_legal)';
            $like = self::likeContains($filters['q']);
            $params += ['q_name' => $like, 'q_city' => $like, 'q_legal' => $like];
        }
        if (isset($filters['status'])) {
            $conditions[] = 'c.status = :status';
            $params['status'] = $filters['status'];
        }

        return [implode(' AND ', $conditions), $params];
    }
}
