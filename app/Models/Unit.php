<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\TenantModel;

/**
 * Units (apartments/houses) of the current condominium.
 */
final class Unit extends TenantModel
{
    /** "Bloco B 1203", or just "1203" for single-building condominiums. */
    public const LABEL_SQL = "CONCAT_WS(' ', NULLIF(u.building, ''), u.unit_number)";

    protected string $table = 'units';

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
}
