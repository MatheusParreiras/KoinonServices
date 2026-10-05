<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Pagination;
use App\Core\TenantModel;

/**
 * Units (apartments/houses) of the current condominium.
 */
final class Unit extends TenantModel
{
    /** "Bloco B 1203", or just "1203" for single-building condominiums. */
    public const LABEL_SQL = "CONCAT_WS(' ', NULLIF(u.building, ''), u.unit_number)";

    public const TYPES = [
        'apartment'  => 'Apartamento',
        'house'      => 'Casa',
        'commercial' => 'Comercial',
        'other'      => 'Outro',
    ];

    protected string $table = 'units';

    // condominium_id is intentionally absent: TenantModel::insert() sets it.
    protected array $fillable = ['building', 'unit_number', 'floor_number', 'unit_type', 'is_active'];

    /**
     * Active units of the current tenant, for <select> lists.
     *
     * @return list<array{id: int, label: string}>
     */
    public function active(): array
    {
        return $this->fetchAll(
            'SELECT u.id, ' . self::LABEL_SQL . ' AS label
               FROM units u
              WHERE u.condominium_id = :tenant   -- tenant scope
                AND u.is_active = 1
              ORDER BY u.building, LENGTH(u.unit_number), u.unit_number',
            $this->scoped()
        );
    }

    /**
     * One active unit of the current tenant, or null.
     *
     * This is the "unit belongs to the condominium" check: an id from another
     * condominium is simply not found, whatever the client sent.
     */
    public function findActive(int $id): ?array
    {
        return $this->fetchOne(
            'SELECT u.id, ' . self::LABEL_SQL . ' AS label
               FROM units u
              WHERE u.condominium_id = :tenant AND u.id = :id AND u.is_active = 1',
            $this->scoped(['id' => $id])
        );
    }

    /**
     * One page of units for the admin list.
     *
     * @param array{q?: string, building?: string, active?: bool} $filters Already validated.
     * @return list<array<string, mixed>>
     */
    public function search(array $filters, Pagination $pagination): array
    {
        [$where, $params] = $this->filterSql($filters);

        return $this->fetchAll(
            "SELECT u.id, u.building, u.unit_number, u.floor_number, u.unit_type, u.is_active,
                    " . self::LABEL_SQL . " AS label,
                    (SELECT COUNT(*) FROM unit_residents ur
                      WHERE ur.condominium_id = u.condominium_id AND ur.unit_id = u.id
                        AND ur.move_out_date IS NULL) AS resident_count
               FROM units u
              WHERE {$where}
              ORDER BY u.building, LENGTH(u.unit_number), u.unit_number
              LIMIT :limit OFFSET :offset",
            $params + ['limit' => $pagination->limit(), 'offset' => $pagination->offset()]
        );
    }

    /** @param array{q?: string, building?: string, active?: bool} $filters */
    public function count(array $filters): int
    {
        [$where, $params] = $this->filterSql($filters);
        $row = $this->fetchOne("SELECT COUNT(*) AS total FROM units u WHERE {$where}", $params);

        return (int) ($row['total'] ?? 0);
    }

    /**
     * Distinct buildings/towers of the tenant, for the filter.
     *
     * @return list<string>
     */
    public function buildings(): array
    {
        $rows = $this->fetchAll(
            "SELECT DISTINCT building FROM units WHERE condominium_id = :tenant AND building <> '' ORDER BY building",
            $this->scoped()
        );

        return array_map(static fn (array $r): string => (string) $r['building'], $rows);
    }

    /**
     * Inserts a unit unless (building, unit_number) already exists in this tenant.
     *
     * @return bool True when a row was inserted, false for an existing label.
     */
    public function insertIfMissing(string $building, string $unitNumber, ?int $floor, string $type): bool
    {
        return $this->execute(
            'INSERT INTO units (condominium_id, building, unit_number, floor_number, unit_type)
             VALUES (:tenant, :building, :unit_number, :floor_number, :unit_type)
             ON DUPLICATE KEY UPDATE id = id',
            $this->scoped([
                'building'     => $building,
                'unit_number'  => $unitNumber,
                'floor_number' => $floor,
                'unit_type'    => $type,
            ])
        ) === 1;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function filterSql(array $filters): array
    {
        $conditions = ['u.condominium_id = :tenant'];   // tenant scope
        $params = [];
        if (isset($filters['q']) && $filters['q'] !== '') {
            $conditions[] = 'u.unit_number LIKE :q';
            $params['q'] = self::likeContains($filters['q']);
        }
        if (isset($filters['building'])) {
            $conditions[] = 'u.building = :building';
            $params['building'] = $filters['building'];
        }
        if (isset($filters['active'])) {
            $conditions[] = 'u.is_active = :active';
            $params['active'] = $filters['active'] ? 1 : 0;
        }

        return [implode(' AND ', $conditions), $this->scoped($params)];
    }
}
