<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Model;

/**
 * The tenant registry `condominiums` (global table, managed by the Super Admin).
 */
final class Condominium extends Model
{
    protected string $table = 'condominiums';

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
}
