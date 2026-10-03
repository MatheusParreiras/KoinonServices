<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\TenantModel;

/**
 * Tickets of the digital incident book (`occurrences`).
 *
 * Visibility rule, applied inside every read query (not after fetching):
 *  - staff who see everything (manager, Super Admin): any ticket of the tenant;
 *  - everyone else: only tickets they reported (reported_by_user_id = me).
 */
final class Occurrence extends TenantModel
{
    /** Ticket types offered in Phase 3, mapped to the Phase 1 ENUM values. */
    public const TYPES = [
        'complaint'   => 'Reclamação',
        'maintenance' => 'Relato de dano',
    ];

    public const CATEGORIES = [
        'noise'            => 'Barulho',
        'security'         => 'Segurança',
        'maintenance'      => 'Manutenção / dano',
        'cleaning'         => 'Limpeza',
        'parking'          => 'Garagem',
        'pets'             => 'Animais',
        'neighbor_conduct' => 'Conduta de vizinho',
        'other'            => 'Outro',
    ];

    /** Statuses used in Phase 3 (subset of the Phase 1 ENUM). */
    public const STATUSES = [
        'open'        => 'Aberta',
        'in_progress' => 'Em andamento',
        'resolved'    => 'Resolvida',
    ];

    protected string $table = 'occurrences';

    protected array $fillable = [
        'protocol_number',
        'reported_by_user_id',
        'unit_id',
        'occurrence_type',
        'category',
        'title',
        'description',
        'location',
        'occurred_at',
        'status',
    ];

    private const SELECT = 'SELECT o.id, o.protocol_number, o.occurrence_type, o.category, o.title, o.description,
               o.location, o.occurred_at, o.status, o.created_at, o.updated_at, o.resolved_at,
               o.reported_by_user_id, us.full_name AS reporter_name
          FROM occurrences o
          JOIN users us ON us.id = o.reported_by_user_id
         WHERE o.condominium_id = :tenant ';

    /**
     * Tickets visible to the user, newest first.
     *
     * @param bool        $seeAll True only for roles allowed to see every ticket.
     * @param string|null $status Optional filter (already whitelisted by the controller).
     * @return list<array<string, mixed>>
     */
    public function listVisible(int $userId, bool $seeAll, ?string $status): array
    {
        $sql = self::SELECT;
        $params = $this->scoped();

        if (!$seeAll) {
            // Ownership: a resident's query is restricted to their own tickets in SQL,
            // so other people's tickets are never even loaded.
            $sql .= 'AND o.reported_by_user_id = :user_id ';
            $params['user_id'] = $userId;
        }
        if ($status !== null) {
            $sql .= 'AND o.status = :status ';
            $params['status'] = $status;
        }

        return $this->fetchAll($sql . 'ORDER BY o.created_at DESC LIMIT 200', $params);
    }

    /**
     * One ticket if the user may see it, otherwise null.
     *
     * A ticket of another condominium, or another resident's ticket, gives the
     * same null as a non-existent id. The controller answers 404 in all three
     * cases, so editing the id in the URL reveals nothing (IDOR protection).
     */
    public function findVisible(int $id, int $userId, bool $seeAll): ?array
    {
        $sql = self::SELECT . 'AND o.id = :id ';
        $params = $this->scoped(['id' => $id]);
        if (!$seeAll) {
            $sql .= 'AND o.reported_by_user_id = :user_id ';
            $params['user_id'] = $userId;
        }

        return $this->fetchOne($sql, $params);
    }

    /**
     * Changes the status only if it is still $from (optimistic concurrency: if
     * two managers act at once, the second sees 0 rows changed instead of
     * silently overwriting the first). resolved_at is set when it becomes 'resolved'.
     */
    public function changeStatus(int $id, string $from, string $to): bool
    {
        return $this->execute(
            "UPDATE occurrences
                SET status = :to_status,
                    resolved_at = CASE WHEN :to_check = 'resolved' THEN UTC_TIMESTAMP() ELSE resolved_at END
              WHERE id = :id
                AND condominium_id = :tenant
                AND status = :from_status",
            $this->scoped(['id' => $id, 'to_status' => $to, 'to_check' => $to, 'from_status' => $from])
        ) === 1;
    }
}
