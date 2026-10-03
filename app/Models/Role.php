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
}
