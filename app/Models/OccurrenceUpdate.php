<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\TenantModel;

/**
 * Append-only timeline of a ticket (`occurrence_updates`): replies and status changes.
 */
final class OccurrenceUpdate extends TenantModel
{
    protected string $table = 'occurrence_updates';

    protected array $fillable = [
        'occurrence_id',
        'author_user_id',
        'message',
        'status_from',
        'status_to',
        'is_internal',
    ];

    /**
     * Timeline of a ticket, oldest first.
     *
     * Call only after the ticket itself passed Occurrence::findVisible(): this
     * method scopes by tenant but not by reporter.
     *
     * @param bool $includeInternal Staff notes (is_internal = 1) are only for staff;
     *                              they are filtered out in SQL for everyone else.
     * @return list<array<string, mixed>>
     */
    public function forOccurrence(int $occurrenceId, bool $includeInternal): array
    {
        $sql = 'SELECT ou.id, ou.message, ou.status_from, ou.status_to, ou.is_internal, ou.created_at,
                       ou.author_user_id, us.full_name AS author_name, r.code AS author_role
                  FROM occurrence_updates ou
                  JOIN users us ON us.id = ou.author_user_id
                  JOIN condominium_users cu
                    ON cu.condominium_id = ou.condominium_id AND cu.user_id = ou.author_user_id
                  JOIN roles r ON r.id = cu.role_id
                 WHERE ou.condominium_id = :tenant
                   AND ou.occurrence_id = :occurrence_id';
        if (!$includeInternal) {
            $sql .= ' AND ou.is_internal = 0';
        }

        return $this->fetchAll(
            $sql . ' ORDER BY ou.created_at, ou.id',
            $this->scoped(['occurrence_id' => $occurrenceId])
        );
    }
}
