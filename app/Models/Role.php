<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Model;

/**
 * Global role catalogue `roles` (manager, concierge, resident).
 * Super Admin is not a row here: it is users.is_super_admin.
 */
final class Role extends Model
{
    protected string $table = 'roles';

    public function idByCode(string $code): ?int
    {
        $row = $this->fetchOne('SELECT id FROM roles WHERE code = :code', ['code' => $code]);

        return $row === null ? null : (int) $row['id'];
    }

    public function codeById(int $id): ?string
    {
        $row = $this->fetchOne('SELECT code FROM roles WHERE id = :id', ['id' => $id]);

        return $row === null ? null : (string) $row['code'];
    }

    /**
     * Role ids keyed by code, limited to the given codes (an allowlist written
     * in code). Used to turn a submitted role code into an id safely.
     *
     * @param list<string> $codes
     * @return array<string, int>
     */
    public function idsByCodes(array $codes): array
    {
        $ids = [];
        foreach ($this->fetchAll('SELECT id, code FROM roles ORDER BY id') as $row) {
            if (in_array($row['code'], $codes, true)) {
                $ids[(string) $row['code']] = (int) $row['id'];
            }
        }

        return $ids;
    }
}
